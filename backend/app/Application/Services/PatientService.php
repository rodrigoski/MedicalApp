<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\CreatePatientData;
use App\Application\DTOs\ListQuery;
use App\Application\DTOs\UpdatePatientData;
use App\Domain\Contracts\ClockInterface;
use App\Domain\Contracts\DomainEventRepositoryInterface;
use App\Domain\Contracts\PatientRepositoryInterface;
use App\Domain\Entities\Patient;
use App\Domain\Enums\DomainEventType;
use App\Domain\Enums\ResourceStatus;
use App\Domain\Exceptions\DuplicateResource;
use App\Domain\Exceptions\ResourceNotFound;
use App\Domain\ValueObjects\DomainEvent;
use App\Domain\ValueObjects\PagedResult;
use Carbon\CarbonImmutable;

/**
 * CASOS DE USO de Paciente.
 *
 * Este servicio es el unico autorizado a escribir en el repositorio de
 * pacientes. Responsabilidades:
 *   1. Orquestar: cargar el agregado, delegar el comportamiento en la entidad,
 *      persistir el resultado.
 *   2. Aplicar reglas de negocio que dependen de OTROS datos (unicidad), que la
 *      entidad no puede conocer por si sola.
 *   3. Traducir fallos del dominio en errores de aplicacion.
 *   4. Registrar el evento de dominio en la outbox.
 *
 * Lo que NO hace (frontera de responsabilidad de la capa):
 *   - No conoce HTTP: no lee Request, no devuelve JsonResponse.
 *   - No conoce SQL ni Eloquent: habla con PatientRepositoryInterface.
 */
final class PatientService
{
    public function __construct(
        private readonly PatientRepositoryInterface $patients,
        private readonly DomainEventRepositoryInterface $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(CreatePatientData $data): Patient
    {
        // REGLA DE NEGOCIO: el documento identifica a un paciente y es unico.
        // El indice unico parcial en PostgreSQL es la garantia definitiva; esta
        // comprobacion existe para devolver un 409 legible en el caso normal.
        $existing = $this->patients->findByDocumentId($data->documentId);
        if ($existing !== null && ! $existing->isDeleted()) {
            throw DuplicateResource::patientDocument($data->documentId->value());
        }

        if ($data->email !== null && $this->patients->emailExists($data->email->value())) {
            throw DuplicateResource::patientEmail($data->email->value());
        }

        $patient = Patient::register(
            fullName: $data->fullName,
            documentId: $data->documentId,
            email: $data->email,
            phone: $data->phone,
            birthDate: $data->birthDate,
            gender: $data->gender,
            address: $data->address,
            emergencyContactName: $data->emergencyContactName,
            emergencyContactPhone: $data->emergencyContactPhone,
            allergies: $data->allergies,
            now: $this->now(),
        );

        $saved = $this->patients->save($patient);

        $this->recordEvent(DomainEventType::PATIENT_REGISTERED, 'patient', (int) $saved->id(), [
            'patient_id' => $saved->id(),
            'full_name' => $saved->fullName(),
            // El documento NO viaja en el evento: identifica a la persona, y el
            // microservicio de notificaciones no lo necesita para componer un
            // aviso. El `patient_id` ya permite recuperar todo lo que haga
            // falta desde el sistema de origen. Lo que sale del sistema
            // controlado se reduce a lo imprescindible.
            'email' => $saved->email()?->masked(),
        ]);

        return $saved;
    }

    public function update(int $id, UpdatePatientData $data): Patient
    {
        $patient = $this->findOrFail($id);

        if ($data->email !== null && $this->patients->emailExists($data->email->value(), $id)) {
            throw DuplicateResource::patientEmail($data->email->value());
        }

        $now = $this->now();

        if ($data->fullName !== null) {
            $patient->rename($data->fullName, $now);
        }

        if ($data->email !== null) {
            $patient->changeEmail($data->email, $now);
        }

        if ($data->phone !== null || $data->address !== null) {
            $patient->changeContactData($data->phone, $data->address, $now);
        }

        if ($data->birthDate !== null) {
            $patient->changeBirthDate($data->birthDate);
        }

        if ($data->gender !== null) {
            $patient->changeGender($data->gender);
        }

        if ($data->emergencyContactName !== null || $data->emergencyContactPhone !== null) {
            $patient->changeEmergencyContact(
                $data->emergencyContactName ?? $patient->emergencyContactName(),
                $data->emergencyContactPhone ?? $patient->emergencyContactPhone(),
                $now,
            );
        }

        // Solo se tocan las alergias si el cliente las informo. Un PATCH que no
        // menciona "allergies" no debe borrarlas.
        if ($data->allergies !== null) {
            $patient->changeAllergies($data->allergies);
        }

        if ($data->status === ResourceStatus::INACTIVE->value || $data->isActive === false) {
            $patient->deactivate($now);
        } elseif ($data->status === ResourceStatus::ACTIVE->value || $data->isActive === true) {
            $patient->activate($now);
        }

        $saved = $this->patients->update($patient);

        $this->recordEvent(DomainEventType::PATIENT_UPDATED, 'patient', (int) $saved->id(), [
            'patient_id' => $saved->id(),
            'changed_fields' => array_keys($data->raw),
        ]);

        return $saved;
    }

    /**
     * Borrado logico. El registro se conserva para auditoria clinica; ademas se
     * libera el indice unico del documento para permitir un alta posterior.
     */
    public function delete(int $id): void
    {
        $patient = $this->findOrFail($id);

        $this->patients->softDelete($patient);
        $patient->markAsDeleted();

        $this->recordEvent(DomainEventType::PATIENT_DELETED, 'patient', $id, [
            'patient_id' => $id,
        ]);
    }

    public function find(int $id): Patient
    {
        return $this->findOrFail($id);
    }

    /**
     * @return PagedResult<Patient>
     */
    public function list(ListQuery $query): PagedResult
    {
        /** @var PagedResult<Patient> $result */
        $result = $this->patients->paginate($query->page, $query->perPage, [
            'search' => $query->search,
            'status' => $query->filters['status'] ?? null,
            'sort_by' => $query->sortBy,
            'sort_direction' => $query->sortDirection,
            'include_deleted' => (bool) ($query->filters['include_deleted'] ?? false),
        ]);

        return $result;
    }

    private function findOrFail(int $id): Patient
    {
        $patient = $this->patients->findById($id);

        if ($patient === null || $patient->isDeleted()) {
            throw ResourceNotFound::patient($id);
        }

        return $patient;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordEvent(
        DomainEventType $type,
        string $aggregateType,
        int $aggregateId,
        array $payload,
    ): void {
        $this->events->record(DomainEvent::make(
            type: $type->value,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payload: $payload,
            occurredAt: $this->now()->toIso8601String(),
        ));
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->clock->now());
    }
}
