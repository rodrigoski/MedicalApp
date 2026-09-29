<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;

/**
 * DTO para reprogramar una cita existente.
 *
 * Se separa de BookAppointmentData a proposito: son dos casos de uso distintos
 * con reglas distintas (reprogramar conserva estado, reason y notas).
 */
final readonly class RescheduleAppointmentData
{
    public function __construct(
        public TimeRange $newRange,
        public ?string $reason = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $start = (string) ($input['starts_at'] ?? '');
        $end = (string) ($input['ends_at'] ?? '');

        // Si no se envia "ends_at", se deduce de "duration_minutes" (o 30 min).
        if ($end === '' && $start !== '') {
            $minutes = (int) ($input['duration_minutes'] ?? 30);
            $end = CarbonImmutable::parse($start, 'UTC')
                ->addMinutes(max(1, $minutes))
                ->utc()
                ->toIso8601String();
        }

        return new self(
            newRange: TimeRange::forAppointment($start, $end),
            reason: isset($input['reason']) ? (string) $input['reason'] : null,
        );
    }
}
