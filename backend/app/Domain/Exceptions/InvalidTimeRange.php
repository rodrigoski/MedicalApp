<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\TimeRange;

/**
 * El intervalo [inicio, fin) no cumple las invariantes de una cita.
 *
 * Es semiabierto a proposito: dos citas consecutivas (10:00-10:30 y 10:30-11:00)
 * NO se traslapan porque comparten solo el borde.
 */
final class InvalidTimeRange extends DomainException
{
    public function __construct(
        string $message,
        private readonly TimeRange $range,
        private readonly string $reason = 'invalid_range',
    ) {
        parent::__construct($message);
    }

    public static function endBeforeStart(TimeRange $range): self
    {
        return new self(
            'La hora de fin de la cita debe ser posterior a la hora de inicio.',
            $range,
            'end_before_start',
        );
    }

    public static function durationOutOfRange(
        TimeRange $range,
        int $minutes,
        int $min,
        int $max,
    ): self {
        return new self(
            "La duracion de la cita debe estar entre {$min} y {$max} minutos (recibido: {$minutes}).",
            $range,
            'duration_out_of_range',
        );
    }

    public static function outsideWorkingHours(TimeRange $range, string $window): self
    {
        return new self(
            "La cita queda fuera del horario de atencion ({$window}).",
            $range,
            'outside_working_hours',
        );
    }

    public function errorCode(): string
    {
        return 'time_range.'.$this->reason;
    }

    public function context(): array
    {
        return [
            'reason' => $this->reason,
            'starts_at' => $this->range->startAt()->toIso8601String(),
            'ends_at' => $this->range->endAt()->toIso8601String(),
            'duration_minutes' => $this->range->durationInMinutes(),
        ];
    }
}
