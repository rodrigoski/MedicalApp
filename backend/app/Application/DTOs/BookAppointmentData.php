<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\Enums\AppointmentStatus;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;

/**
 * DTO de entrada para agendar una cita.
 *
 * El intervalo YA viene validado como TimeRange desde la capa HTTP; el servicio
 * lo vuelve a validar en el dominio (defensa en profundidad: el DTO no es una
 * frontera de confianza).
 */
final readonly class BookAppointmentData
{
    public function __construct(
        public int $patientId,
        public int $doctorId,
        public TimeRange $timeRange,
        public ?string $reason = null,
        public ?string $notes = null,
        public AppointmentStatus $initialStatus = AppointmentStatus::PENDING,
        public ?CarbonImmutable $now = null,
    ) {
    }
}
