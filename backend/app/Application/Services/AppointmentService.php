<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\BookAppointmentData;
use App\Application\DTOs\ChangeStatusData;
use App\Application\DTOs\ListQuery;
use App\Application\DTOs\RescheduleAppointmentData;
use App\Application\Exceptions\PreconditionFailedException;
use App\Domain\Contracts\AppointmentRepositoryInterface;
use App\Domain\Contracts\ClockInterface;
use App\Domain\Contracts\DoctorRepositoryInterface;
use App\Domain\Contracts\DomainEventRepositoryInterface;
use App\Domain\Contracts\PatientRepositoryInterface;
use App\Domain\Entities\Appointment;
use App\Domain\Entities\Doctor;
use App\Domain\Entities\Patient;
use App\Domain\Enums\AppointmentStatus;
use App\Domain\Enums\DomainEventType;
use App\Domain\Exceptions\ResourceNotFound;
use App\Domain\Policies\AppointmentOverlapPolicy;
use App\Domain\ValueObjects\DomainEvent;
use App\Domain\ValueObjects\PagedResult;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * CASOS DE USO de Cita. Es la orquestadora de la REGLA DE NO TRASLAPE.
 *
 * Secuencia de una reserva (importante para la exposicion del proyecto):
 *   1. Cargar el paciente y el medico.
 *   2. Construir y validar el TimeRange (capas de valor).
 *   3. Consultar al repositorio los intervalos YA OCUPADOS por el medico y por
 *      el paciente en esa ventana (consulta acotada por indice, no tabla completa).
 *   4. Delegar el VEREDICTO en AppointmentOverlapPolicy (Dominio puro).
 *   5. Crear el agregado, persistirlo y registrar el evento.
 *
 * Nota sobre la exclusion constraint de PostgreSQL: ademas de esta validacion
 * en la aplicacion, la tabla `appointments` tiene una restriccion EXCLUDE por
 * rangos que hace imposible el traslape a nivel de motor. Asi la garantia se
 * mantiene aunque dos peticiones simultaneas superen la validacion de PHP
 * (condicion de carrera). Ver la migracion 2024_01_01_000300.
 */
final class AppointmentService
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointments,
        private readonly PatientRepositoryInterface $patients,
        private readonly DoctorRepositoryInterface $doctors,
        private readonly DomainEventRepositoryInterface $events,
        private readonly AppointmentOverlapPolicy $overlapPolicy,
        private readonly ClockInterface $clock,
    ) {
    }

    public function book(BookAppointmentData $data): Appointment
    {
        $patient = $this->findPatientOrFail($data->patientId);
        $doctor = $this->findDoctorOrFail($data->doctorId);
        $now = $this->now();

        $this->assertBookable(
            requested: $data->timeRange,
            doctor: $doctor,
            patient: $patient,
            now: $now,
            excludeAppointmentId: null,
        );

        $appointment = Appointment::book(
            patientId: $patient->id(),
            doctorId: $doctor->id(),
            timeRange: $data->timeRange,
            reason: $data->reason,
            now: $now,
        );

        if ($data->notes !== null) {
            $appointment->addNotes($data->notes, $now);
        }

        // Si el cliente pide confirmacion inmediata, se confirma recien creada.
        if ($data->initialStatus === AppointmentStatus::CONFIRMED) {
            $appointment->confirm($now);
        }

        $saved = $this->appointments->save($appointment);

        $this->recordEvent(DomainEventType::APPOINTMENT_BOOKED, 'appointment', (int) $saved->id(), [
            'appointment_id' => $saved->id(),
            'patient_id' => $saved->patientId(),
            'doctor_id' => $saved->doctorId(),
            'starts_at' => $saved->timeRange()->startAt()->toIso8601String(),
            'ends_at' => $saved->timeRange()->endAt()->toIso8601String(),
            'status' => $saved->status()->value,
        ]);

        return $saved;
    }

    public function changeStatus(int $id, ChangeStatusData $data): Appointment
    {
        $appointment = $this->findOrFail($id);
        $now = $this->now();

        $target = $data->target;

        if ($target === AppointmentStatus::CANCELLED) {
            // REGLA DE NEGOCIO: la cancelacion exige motivo. Sin el, la
            // auditoria de la agenda no puede explicar por que se libero el turno.
            if ($data->reason === null || trim($data->reason) === '') {
                throw new PreconditionFailedException(
                    'Debe indicar el motivo de la cancelacion (campo "reason").',
                    ['field' => 'reason'],
                );
            }

            $appointment->cancel($data->reason, $now);
        } elseif ($target === AppointmentStatus::CONFIRMED) {
            $appointment->confirm($now);
        } elseif ($target === AppointmentStatus::PENDING) {
            $appointment->reopen($now);
        }

        $saved = $this->appointments->update($appointment);

        $event = match ($target) {
            AppointmentStatus::CANCELLED => DomainEventType::APPOINTMENT_CANCELLED,
            AppointmentStatus::CONFIRMED => DomainEventType::APPOINTMENT_CONFIRMED,
            default => DomainEventType::APPOINTMENT_RESCHEDULED,
        };

        $this->recordEvent($event, 'appointment', (int) $saved->id(), [
            'appointment_id' => $saved->id(),
            'patient_id' => $saved->patientId(),
            'doctor_id' => $saved->doctorId(),
            'status' => $saved->status()->value,
            'reason' => $data->reason,
        ]);

        return $saved;
    }

    public function reschedule(int $id, RescheduleAppointmentData $data): Appointment
    {
        $appointment = $this->findOrFail($id);

        if ($appointment->isCancelled()) {
            throw new PreconditionFailedException(
                'No se puede reprogramar una cita cancelada; cree una nueva cita.',
                ['appointment_status' => $appointment->status()->value],
            );
        }

        $patient = $this->findPatientOrFail($appointment->patientId());
        $doctor = $this->findDoctorOrFail($appointment->doctorId());
        $now = $this->now();

        $this->assertBookable(
            requested: $data->newRange,
            doctor: $doctor,
            patient: $patient,
            now: $now,
            excludeAppointmentId: $id,
        );

        $previousRange = $appointment->timeRange();
        $appointment->reschedule($data->newRange, $now);

        if ($data->reason !== null) {
            $appointment->changeReason($data->reason, $now);
        }

        $saved = $this->appointments->update($appointment);

        $this->recordEvent(DomainEventType::APPOINTMENT_RESCHEDULED, 'appointment', (int) $saved->id(), [
            'appointment_id' => $saved->id(),
            'patient_id' => $saved->patientId(),
            'doctor_id' => $saved->doctorId(),
            'previous_starts_at' => $previousRange->startAt()->toIso8601String(),
            'previous_ends_at' => $previousRange->endAt()->toIso8601String(),
            'starts_at' => $saved->timeRange()->startAt()->toIso8601String(),
            'ends_at' => $saved->timeRange()->endAt()->toIso8601String(),
        ]);

        return $saved;
    }

    /**
     * Reprograma una cita moviendola N minutos. Mismo caso de uso que
     * reschedule() pero con la semantica de "reprogramar rapido" que usa el
     * panel de recepcion. Se apoya en el MISMO camino de validacion, por lo que
     * no puede saltarse la regla de no traslape.
     */
    public function shift(int $id, int $minutes, ?string $reason = null): Appointment
    {
        $appointment = $this->findOrFail($id);

        if ($minutes === 0) {
            throw new PreconditionFailedException(
                'El desplazamiento debe ser distinto de cero minutos.',
                ['minutes' => $minutes],
            );
        }

        $newRange = TimeRange::forAppointment(
            $appointment->timeRange()->startAt()->addMinutes($minutes),
            $appointment->timeRange()->endAt()->addMinutes($minutes),
        );

        return $this->reschedule($id, new RescheduleAppointmentData($newRange, $reason));
    }

    public function cancel(int $id, string $reason): Appointment
    {
        return $this->changeStatus($id, new ChangeStatusData(
            target: AppointmentStatus::CANCELLED,
            reason: $reason,
        ));
    }

    public function confirm(int $id): Appointment
    {
        return $this->changeStatus($id, new ChangeStatusData(AppointmentStatus::CONFIRMED));
    }

    public function find(int $id): Appointment
    {
        return $this->findOrFail($id);
    }

    /**
     * @return PagedResult<Appointment>
     */
    public function list(ListQuery $query): PagedResult
    {
        /** @var PagedResult<Appointment> $result */
        $result = $this->appointments->paginate($query->page, $query->perPage, [
            'doctor_id' => $query->filters['doctor_id'] ?? null,
            'patient_id' => $query->filters['patient_id'] ?? null,
            'status' => $query->filters['status'] ?? null,
            'date' => $query->filters['date'] ?? null,
            'from' => $query->filters['from'] ?? null,
            'to' => $query->filters['to'] ?? null,
            'sort_by' => $query->sortBy,
            'sort_direction' => $query->sortDirection,
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function statistics(): array
    {
        return $this->appointments->statistics();
    }

    /**
     * Verificacion central de disponibilidad. Se extrae a un metodo privado
     * porque la usan tanto book() como reschedule(): no se duplica la regla.
     *
     * @throws \App\Domain\Exceptions\AppointmentConflict
     */
    private function assertBookable(
        TimeRange $requested,
        Doctor $doctor,
        Patient $patient,
        CarbonImmutable $now,
        ?int $excludeAppointmentId,
    ): void {
        $windowStart = $requested->startAt()->subHour();
        $windowEnd = $requested->endAt()->addHour();

        $doctorBusy = $this->appointments->busyRangesForDoctor(
            doctorId: (int) $doctor->id(),
            from: $windowStart,
            to: $windowEnd,
            excludeAppointmentId: $excludeAppointmentId,
        );

        $patientBusy = $this->appointments->busyRangesForPatient(
            patientId: (int) $patient->id(),
            from: $windowStart,
            to: $windowEnd,
            excludeAppointmentId: $excludeAppointmentId,
        );

        // El "dia" del techo diario es el dia de la clinica, no el dia UTC: para
        // un hospital en Bogota la jornada termina a las 18:00 locales (23:00
        // UTC). Contar por UTC permitiria colar citas de madrugada en el dia
        // siguiente y superar el limite sin que nadie lo note.
        [$dayFrom, $dayTo] = $this->clinicDayWindow($requested->startAt());

        $countOnDay = $this->appointments->countActiveForDoctorBetween(
            doctorId: (int) $doctor->id(),
            from: $dayFrom,
            to: $dayTo,
        );

        $this->overlapPolicy->assertCanBook(
            requested: $requested,
            doctor: $doctor,
            patient: $patient,
            doctorBusyRanges: $doctorBusy,
            patientBusyRanges: $patientBusy,
            existingAppointmentsOnDay: $countOnDay,
            now: $now,
        );
    }

    /**
     * Ventana [desde, hasta) del dia de la clinica que contiene al instante dado,
     * expresada en UTC.
     *
     * La politica define la zona (es la unica que sabe a que hora abre el
     * consultorio); aqui solo se traduce ese dia local a UTC para consultar.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function clinicDayWindow(CarbonImmutable $instant): array
    {
        $localDay = $instant
            ->setTimezone(new DateTimeZone($this->overlapPolicy->clinicTimezone()))
            ->startOfDay();

        return [$localDay->utc(), $localDay->addDay()->utc()];
    }

    private function findOrFail(int $id): Appointment
    {
        $appointment = $this->appointments->findById($id);

        if ($appointment === null) {
            throw ResourceNotFound::appointment($id);
        }

        return $appointment;
    }

    private function findPatientOrFail(int $id): Patient
    {
        $patient = $this->patients->findById($id);

        if ($patient === null || $patient->isDeleted()) {
            throw ResourceNotFound::patient($id);
        }

        return $patient;
    }

    private function findDoctorOrFail(int $id): Doctor
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
