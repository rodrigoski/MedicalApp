<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\AvailabilityResult;
use App\Domain\Contracts\AppointmentRepositoryInterface;
use App\Domain\Contracts\DoctorRepositoryInterface;
use App\Domain\Entities\Doctor;
use App\Domain\Exceptions\ResourceNotFound;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * CALCULO DE DISPONIBILIDAD.
 *
 * Es un caso de uso de SOLO LECTURA: nunca escribe, nunca bloquea. Responde
 * "que huecos tiene este medico el dia tal". Sirve tanto para el formulario de
 * reserva como para las pruebas de aceptacion de la regla de no traslape.
 *
 * Tecnica: "sweep line" sobre la jornada. Se generan todos los slots posibles
 * y se descartan los que se traslapan con alguna cita ocupada. Complejidad
 * O(n + m) en lugar de comparar cada par (que seria O(n*m)).
 */
final class AvailabilityService
{
    public function __construct(
        private readonly DoctorRepositoryInterface $doctors,
        private readonly AppointmentRepositoryInterface $appointments,
        private readonly string $clinicTimezone = 'UTC',
    ) {
    }

    public function forDoctorOnDate(
        int $doctorId,
        string $date,
        ?int $slotMinutes = null,
    ): AvailabilityResult {
        $doctor = $this->doctors->findById($doctorId);
        if ($doctor === null || $doctor->isDeleted()) {
            throw ResourceNotFound::doctor($doctorId);
        }

        return $this->forDoctorAndDate($doctor, $date, $slotMinutes);
    }

    public function forDoctorAndDate(
        Doctor $doctor,
        string $date,
        ?int $slotMinutes = null,
    ): AvailabilityResult {
        $timezone = new DateTimeZone($this->clinicTimezone);

        // El dia se interpreta en la HORA LOCAL de la clinica, igual que hace la
        // politica de traslape. Si la jornada se calculara en UTC, el calendario
        // ofreceria (y la aceptacion permitiria) horas que despues la reserva
        // rechazaria: el usuario veria un hueco y no podria ocuparlo.
        $localDay = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $slot = $slotMinutes ?? $doctor->slotDurationMinutes();
        $slot = max(5, min(240, $slot));

        // Domingo: la clinica no atiende.
        if ($localDay->dayOfWeek === CarbonImmutable::SUNDAY) {
            return new AvailabilityResult(
                doctorId: (int) $doctor->id(),
                date: $localDay->toDateString(),
                slotMinutes: $slot,
                freeSlots: [],
                occupiedSlots: [],
            );
        }

        // La jornada ("de 8 a 18") es un fenomeno del reloj de pared del
        // consultorio; se construye en hora local y se lleva a UTC para
        // consultar y comparar contra los intervalos ya almacenados.
        $windowStart = $localDay->setTime($doctor->workingDayStartHour(), 0)->utc();
        $windowEnd = $localDay->setTime($doctor->workingDayEndHour(), 0)->utc();

        $occupied = $this->appointments->busyRangesForDoctor(
            doctorId: (int) $doctor->id(),
            from: $windowStart->subDay(),
            to: $windowEnd->addDay(),
        );

        /** @var list<TimeRange> $dayOccupied */
        $dayOccupied = array_values(array_filter(
            $occupied,
            static fn (TimeRange $r): bool => $r->overlaps(TimeRange::from($windowStart, $windowEnd)),
        ));

        // Barrido de la jornada. Los slots se avanzan en UTC (tiempo transcurrido
        // real) para que un cambio de horario de verano no duplique ni salte un
        // slot: la jornada local ya esta fijada arriba.
        $free = [];
        $cursor = $windowStart;
        while ($cursor->addMinutes($slot)->lessThanOrEqualTo($windowEnd)) {
            $candidate = TimeRange::from($cursor, $cursor->addMinutes($slot));

            if (! $candidate->overlapsAny($dayOccupied)) {
                $free[] = $candidate;
            }

            $cursor = $cursor->addMinutes($slot);
        }

        return new AvailabilityResult(
            doctorId: (int) $doctor->id(),
            date: $localDay->toDateString(),
            slotMinutes: $slot,
            freeSlots: $free,
            occupiedSlots: $dayOccupied,
        );
    }
}
