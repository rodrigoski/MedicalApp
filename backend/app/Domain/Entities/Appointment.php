<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enums\AppointmentStatus;
use App\Domain\Exceptions\InvalidStatusTransition;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;

/**
 * Agregado Cita.
 *
 * Concentra TODA la logica de estado y de repeticion de horarios:
 *   - book()      : crea la cita en estado PENDIENTE validando el intervalo.
 *   - confirm()   : PENDIENTE -> CONFIRMADA.
 *   - cancel()    : PENDIENTE|CONFIRMADA -> CANCELADA (terminal).
 *   - reopen()    : CONFIRMADA -> PENDIENTE.
 *   - reschedule(): mueve el intervalo manteniendo el estado.
 *
 * La entidad NO sabe si hay otra cita que se traslape en la base de datos: eso
 * lo resuelve la politica de dominio (AppointmentOverlapPolicy) en la capa de
 * aplicacion, porque requiere consultar las citas existentes. La entidad solo
 * garantiza sus propias invariantes. Esa separacion es lo que permite, por
 * ejemplo, calcular disponibilidad sin bloquear nada.
 */
final class Appointment
{
    private function __construct(
        private ?int $id,
        private readonly int $patientId,
        private readonly int $doctorId,
        private TimeRange $timeRange,
        private AppointmentStatus $status,
        private ?string $reason,
        private ?string $notes,
        private ?CarbonImmutable $confirmedAt,
        private ?CarbonImmutable $cancelledAt,
        private ?string $cancellationReason,
        private readonly CarbonImmutable $createdAt,
        private CarbonImmutable $updatedAt,
    ) {
    }

    public static function book(
        int $patientId,
        int $doctorId,
        TimeRange $timeRange,
        ?string $reason = null,
        ?CarbonImmutable $now = null,
    ): self {
        $timestamp = $now ?? CarbonImmutable::now('UTC');

        return new self(
            id: null,
            patientId: $patientId,
            doctorId: $doctorId,
            timeRange: $timeRange,
            status: AppointmentStatus::PENDING,
            reason: $reason !== null ? trim($reason) : null,
            notes: null,
            confirmedAt: null,
            cancelledAt: null,
            cancellationReason: null,
            createdAt: $timestamp,
            updatedAt: $timestamp,
        );
    }

    /**
     * @internal Reconstruccion desde persistencia.
     */
    public static function reconstitute(
        int $id,
        int $patientId,
        int $doctorId,
        TimeRange $timeRange,
        AppointmentStatus $status,
        ?string $reason,
        ?string $notes,
        ?CarbonImmutable $confirmedAt,
        ?CarbonImmutable $cancelledAt,
        ?string $cancellationReason,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $patientId,
            $doctorId,
            $timeRange,
            $status,
            $reason,
            $notes,
            $confirmedAt,
            $cancelledAt,
            $cancellationReason,
            $createdAt,
            $updatedAt,
        );
    }

    // --- Transiciones de estado ---------------------------------------------

    public function confirm(?CarbonImmutable $now = null): void
    {
        $this->transitionTo(AppointmentStatus::CONFIRMED, $now);
        $this->confirmedAt = $now ?? CarbonImmutable::now('UTC');
    }

    public function cancel(string $reason, ?CarbonImmutable $now = null): void
    {
        $timestamp = $now ?? CarbonImmutable::now('UTC');
        $this->transitionTo(AppointmentStatus::CANCELLED, $timestamp);
        $this->cancelledAt = $timestamp;
        $this->cancellationReason = trim($reason);
    }

    public function reopen(?CarbonImmutable $now = null): void
    {
        $timestamp = $now ?? CarbonImmutable::now('UTC');
        $this->transitionTo(AppointmentStatus::PENDING, $timestamp);
        $this->confirmedAt = null;
    }

    /**
     * Re-programa la cita. La entidad cambia su intervalo; la validacion de
     * traslape la hace la capa de aplicacion antes de invocar este metodo.
     */
    public function reschedule(TimeRange $newRange, ?CarbonImmutable $now = null): void
    {
        $this->timeRange = $newRange;
        $this->touch($now);
    }

    public function addNotes(string $notes, ?CarbonImmutable $now = null): void
    {
        $this->notes = trim($notes);
        $this->touch($now);
    }

    public function changeReason(?string $reason, ?CarbonImmutable $now = null): void
    {
        $this->reason = $reason !== null ? trim($reason) : null;
        $this->touch($now);
    }

    // --- Consultas de estado -------------------------------------------------

    public function status(): AppointmentStatus
    {
        return $this->status;
    }

    public function isCancelled(): bool
    {
        return $this->status === AppointmentStatus::CANCELLED;
    }

    public function isPending(): bool
    {
        return $this->status === AppointmentStatus::PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === AppointmentStatus::CONFIRMED;
    }

    /**
     * Una cita ocupa agenda mientras no este cancelada. Las citas canceladas
     * liberan el horario (y por eso no bloquean en la regla de no-traslape).
     */
    public function occupiesSchedule(): bool
    {
        return $this->status->isActive();
    }

    public function isInPast(?CarbonImmutable $now = null): bool
    {
        return $this->timeRange->endAt()->lessThan($now ?? CarbonImmutable::now('UTC'));
    }

    // --- Accesores -----------------------------------------------------------

    public function id(): ?int
    {
        return $this->id;
    }

    public function patientId(): int
    {
        return $this->patientId;
    }

    public function doctorId(): int
    {
        return $this->doctorId;
    }

    public function timeRange(): TimeRange
    {
        return $this->timeRange;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function confirmedAt(): ?CarbonImmutable
    {
        return $this->confirmedAt;
    }

    public function cancelledAt(): ?CarbonImmutable
    {
        return $this->cancelledAt;
    }

    public function cancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function createdAt(): CarbonImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): CarbonImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patientId,
            'doctor_id' => $this->doctorId,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'starts_at' => $this->timeRange->startAt()->toIso8601String(),
            'ends_at' => $this->timeRange->endAt()->toIso8601String(),
            'duration_minutes' => $this->timeRange->durationInMinutes(),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'confirmed_at' => $this->confirmedAt?->toIso8601String(),
            'cancelled_at' => $this->cancelledAt?->toIso8601String(),
            'cancellation_reason' => $this->cancellationReason,
        ];
    }

    /**
     * @internal
     */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('La entidad ya tiene identificador asignado.');
        }

        $this->id = $id;
    }

    private function transitionTo(AppointmentStatus $target, ?CarbonImmutable $now): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw InvalidStatusTransition::notAllowed($this->status, $target);
        }

        $this->status = $target;
        $this->touch($now);
    }

    private function touch(?CarbonImmutable $now = null): void
    {
        $this->updatedAt = $now ?? CarbonImmutable::now('UTC');
    }
}
