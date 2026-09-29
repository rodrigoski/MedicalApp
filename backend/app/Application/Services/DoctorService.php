<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\CreateDoctorData;
use App\Application\DTOs\ListQuery;
use App\Application\DTOs\UpdateDoctorData;
use App\Domain\Contracts\ClockInterface;
use App\Domain\Contracts\DoctorRepositoryInterface;
use App\Domain\Contracts\DomainEventRepositoryInterface;
use App\Domain\Entities\Doctor;
use App\Domain\Enums\DomainEventType;
use App\Domain\Enums\ResourceStatus;
use App\Domain\Exceptions\DuplicateResource;
use App\Domain\Exceptions\ResourceNotFound;
use App\Domain\ValueObjects\DomainEvent;
use App\Domain\ValueObjects\PagedResult;
use Carbon\CarbonImmutable;

/**
 * CASOS DE USO de Medico. Misma estructura que PatientService: orquesta,
 * aplica reglas que cruzan agregados y registra eventos.
 */
final class DoctorService
{
    public function __construct(
        private readonly DoctorRepositoryInterface $doctors,
        private readonly DomainEventRepositoryInterface $events,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(CreateDoctorData $data): Doctor
    {
        if ($this->doctors->findByLicenseNumber($data->licenseNumber) !== null) {
            throw DuplicateResource::doctorLicense($data->licenseNumber->value());
        }

        if ($data->email !== null && $this->doctors->emailExists($data->email->value())) {
            throw DuplicateResource::doctorEmail($data->email->value());
        }

        $doctor = Doctor::register(
            fullName: $data->fullName,
            licenseNumber: $data->licenseNumber,
            specialty: $data->specialty,
            email: $data->email,
            phone: $data->phone,
            workingDayStartHour: $data->workingDayStartHour,
            workingDayEndHour: $data->workingDayEndHour,
            slotDurationMinutes: $data->slotDurationMinutes,
            now: $this->now(),
        );

        $saved = $this->doctors->save($doctor);

        $this->recordEvent(DomainEventType::DOCTOR_REGISTERED, 'doctor', (int) $saved->id(), [
            'doctor_id' => $saved->id(),
            'full_name' => $saved->fullName(),
            'specialty' => $saved->specialty(),
            'license_number' => $saved->licenseNumber()->value(),
        ]);

        return $saved;
    }

    public function update(int $id, UpdateDoctorData $data): Doctor
    {
        $doctor = $this->findOrFail($id);

        if ($data->email !== null && $this->doctors->emailExists($data->email->value(), $id)) {
            throw DuplicateResource::doctorEmail($data->email->value());
        }

        $now = $this->now();

        if ($data->fullName !== null) {
            $doctor->rename($data->fullName, $now);
        }

        if ($data->specialty !== null) {
            $doctor->changeSpecialty($data->specialty, $now);
        }

        if ($data->email !== null || $data->phone !== null) {
            $doctor->changeContactData($data->email, $data->phone, $now);
        }

        if ($data->workingDayStartHour !== null
            || $data->workingDayEndHour !== null
            || $data->slotDurationMinutes !== null
        ) {
            $doctor->changeSchedule(
                $data->workingDayStartHour ?? $doctor->workingDayStartHour(),
                $data->workingDayEndHour ?? $doctor->workingDayEndHour(),
                $data->slotDurationMinutes ?? $doctor->slotDurationMinutes(),
                $now,
            );
        }

        if ($data->status === ResourceStatus::INACTIVE->value) {
            $doctor->deactivate($now);
        } elseif ($data->status === ResourceStatus::ACTIVE->value) {
            $doctor->activate($now);
        }

        $saved = $this->doctors->update($doctor);

        $this->recordEvent(DomainEventType::DOCTOR_UPDATED, 'doctor', (int) $saved->id(), [
            'doctor_id' => $saved->id(),
        ]);

        return $saved;
    }

    public function delete(int $id): void
    {
        $doctor = $this->findOrFail($id);

        $this->doctors->softDelete($doctor);
        $doctor->markAsDeleted();
    }

    public function find(int $id): Doctor
    {
        return $this->findOrFail($id);
    }

    /**
     * @return PagedResult<Doctor>
     */
    public function list(ListQuery $query): PagedResult
    {
        /** @var PagedResult<Doctor> $result */
        $result = $this->doctors->paginate($query->page, $query->perPage, [
            'search' => $query->search,
            'status' => $query->filters['status'] ?? null,
            'specialty' => $query->filters['specialty'] ?? null,
            'sort_by' => $query->sortBy,
            'sort_direction' => $query->sortDirection,
            'include_deleted' => (bool) ($query->filters['include_deleted'] ?? false),
        ]);

        return $result;
    }

    private function findOrFail(int $id): Doctor
    {
        $doctor = $this->doctors->findById($id);

        if ($doctor === null || $doctor->isDeleted()) {
            throw ResourceNotFound::doctor($id);
        }

        return $doctor;
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
