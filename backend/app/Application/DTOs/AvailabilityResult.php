<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\ValueObjects\TimeRange;

/**
 * Resultado del calculo de disponibilidad de un medico en una fecha.
 *
 * Es un DTO de SALIDA, no una entidad: describe una proyeccion temporal, no una
 * cosa con identidad. Por eso vive en Aplicacion y no en el Dominio.
 */
final readonly class AvailabilityResult
{
    /**
     * @param list<TimeRange> $freeSlots
     * @param list<TimeRange> $occupiedSlots
     */
    public function __construct(
        public int $doctorId,
        public string $date,
        public int $slotMinutes,
        public array $freeSlots,
        public array $occupiedSlots,
    ) {
    }

    public function totalSlots(): int
    {
        return count($this->freeSlots) + count($this->occupiedSlots);
    }

    public function freeSlotsCount(): int
    {
        return count($this->freeSlots);
    }

    /**
     * Porcentaje de ocupacion de la jornada (0.0 - 100.0).
     */
    public function occupancyPercentage(): float
    {
        $total = $this->totalSlots();

        if ($total === 0) {
            return 0.0;
        }

        return round((count($this->occupiedSlots) / $total) * 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'doctor_id' => $this->doctorId,
            'date' => $this->date,
            'slot_minutes' => $this->slotMinutes,
            'summary' => [
                'total_slots' => $this->totalSlots(),
                'free_slots' => $this->freeSlotsCount(),
                'occupied_slots' => count($this->occupiedSlots),
                'occupancy_percentage' => $this->occupancyPercentage(),
            ],
            'free_slots' => array_map(
                static fn (TimeRange $r): array => $r->toArray(),
                $this->freeSlots,
            ),
            'occupied_slots' => array_map(
                static fn (TimeRange $r): array => $r->toArray(),
                $this->occupiedSlots,
            ),
        ];
    }
}
