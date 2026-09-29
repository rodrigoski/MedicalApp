<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enums\ResourceStatus;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\LicenseNumber;
use App\Domain\ValueObjects\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * Agregado Medico.
 *
 * Mismo patron que Patient: constructor privado + factorias controladas. El
 * medico tiene ademas una jornada laboral (working hours) que se usa para
 * calcular disponibilidad.
 */
final class Doctor
{
    private function __construct(
        private ?int $id,
        private string $fullName,
        private readonly LicenseNumber $licenseNumber,
        private string $specialty,
        private ?Email $email,
        private ?PhoneNumber $phone,
        private ResourceStatus $status,
        private bool $isDeleted,
        private int $workingDayStartHour,
        private int $workingDayEndHour,
        private int $slotDurationMinutes,
        private readonly CarbonImmutable $createdAt,
        private CarbonImmutable $updatedAt,
    ) {
    }

    public static function register(
        string $fullName,
        LicenseNumber $licenseNumber,
        string $specialty,
        ?Email $email = null,
        ?PhoneNumber $phone = null,
        int $workingDayStartHour = 8,
        int $workingDayEndHour = 18,
        int $slotDurationMinutes = 30,
        ?CarbonImmutable $now = null,
    ): self {
        $timestamp = $now ?? CarbonImmutable::now('UTC');

        return new self(
            id: null,
            fullName: self::sanitiseName($fullName),
            licenseNumber: $licenseNumber,
            specialty: self::sanitiseSpecialty($specialty),
            email: $email,
            phone: $phone,
            status: ResourceStatus::ACTIVE,
            isDeleted: false,
            workingDayStartHour: self::clampHour($workingDayStartHour),
            workingDayEndHour: self::clampHour($workingDayEndHour),
            slotDurationMinutes: max(5, min(240, $slotDurationMinutes)),
            createdAt: $timestamp,
            updatedAt: $timestamp,
        );
    }

    /**
     * @internal Reconstruccion desde persistencia.
     */
    public static function reconstitute(
        int $id,
        string $fullName,
        LicenseNumber $licenseNumber,
        string $specialty,
        ?Email $email,
        ?PhoneNumber $phone,
        ResourceStatus $status,
        bool $isDeleted,
        int $workingDayStartHour,
        int $workingDayEndHour,
        int $slotDurationMinutes,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $fullName,
            $licenseNumber,
            $specialty,
            $email,
            $phone,
            $status,
            $isDeleted,
            $workingDayStartHour,
            $workingDayEndHour,
            $slotDurationMinutes,
            $createdAt,
            $updatedAt,
        );
    }

    // --- Comportamiento ------------------------------------------------------

    public function rename(string $fullName, ?CarbonImmutable $now = null): void
    {
        $this->fullName = self::sanitiseName($fullName);
        $this->touch($now);
    }

    public function changeContactData(
        ?Email $email,
        ?PhoneNumber $phone,
        ?CarbonImmutable $now = null,
    ): void {
        $this->email = $email;
        $this->phone = $phone;
        $this->touch($now);
    }

    public function changeSpecialty(string $specialty, ?CarbonImmutable $now = null): void
    {
        $this->specialty = self::sanitiseSpecialty($specialty);
        $this->touch($now);
    }

    public function changeSchedule(
        int $startHour,
        int $endHour,
        int $slotDurationMinutes,
        ?CarbonImmutable $now = null,
    ): void {
        $this->workingDayStartHour = self::clampHour($startHour);
        $this->workingDayEndHour = self::clampHour($endHour);
        $this->slotDurationMinutes = max(5, min(240, $slotDurationMinutes));
        $this->touch($now);
    }

    public function deactivate(?CarbonImmutable $now = null): void
    {
        $this->status = ResourceStatus::INACTIVE;
        $this->touch($now);
    }

    public function activate(?CarbonImmutable $now = null): void
    {
        $this->status = ResourceStatus::ACTIVE;
        $this->touch($now);
    }

    public function isActive(): bool
    {
        return $this->status->isActive() && ! $this->isDeleted;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }

    public function worksOn(\DateTimeInterface $date): bool
    {
        $dayOfWeek = (int) $date->format('N');

        // 6 = sabado, 7 = domingo. Se deja configurable el dia sabado via
        // jornada: por defecto el domingo no se atiende.
        return $dayOfWeek !== 7;
    }

    // --- Accesores -----------------------------------------------------------

    public function id(): ?int
    {
        return $this->id;
    }

    public function fullName(): string
    {
        return $this->fullName;
    }

    public function licenseNumber(): LicenseNumber
    {
        return $this->licenseNumber;
    }

    public function specialty(): string
    {
        return $this->specialty;
    }

    public function email(): ?Email
    {
        return $this->email;
    }

    public function phone(): ?PhoneNumber
    {
        return $this->phone;
    }

    public function status(): ResourceStatus
    {
        return $this->status;
    }

    public function workingDayStartHour(): int
    {
        return $this->workingDayStartHour;
    }

    public function workingDayEndHour(): int
    {
        return $this->workingDayEndHour;
    }

    public function slotDurationMinutes(): int
    {
        return $this->slotDurationMinutes;
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
            'full_name' => $this->fullName,
            'license_number' => $this->licenseNumber->value(),
            'specialty' => $this->specialty,
            'email' => $this->email?->value(),
            'phone' => $this->phone?->value(),
            'status' => $this->status->value,
            'schedule' => [
                'start_hour' => $this->workingDayStartHour,
                'end_hour' => $this->workingDayEndHour,
                'slot_minutes' => $this->slotDurationMinutes,
            ],
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

    /**
     * Marca la entidad como eliminada logicamente.
     *
     * El borrado logico conserva la fila por dos motivos de auditoria: el
     * historial de citas del medico debe seguir siendo explicable, y el
     * documento/licencia queda libre para un nuevo registro.
     *
     * @internal Lo invoca la capa de aplicacion tras el UPDATE en la BD.
     */
    public function markAsDeleted(): void
    {
        $this->isDeleted = true;
        $this->status = ResourceStatus::INACTIVE;
    }

    private function touch(?CarbonImmutable $now = null): void
    {
        $this->updatedAt = $now ?? CarbonImmutable::now('UTC');
    }

    private static function sanitiseName(string $name): string
    {
        $normalised = trim((string) preg_replace('/\s+/u', ' ', $name));

        return mb_convert_case($normalised, MB_CASE_TITLE, 'UTF-8');
    }

    private static function sanitiseSpecialty(string $specialty): string
    {
        $normalised = trim((string) preg_replace('/\s+/u', ' ', $specialty));

        return $specialty === $normalised
            ? ucfirst(mb_strtolower($normalised))
            : $normalised;
    }

    private static function clampHour(int $hour): int
    {
        return max(0, min(23, $hour));
    }
}
