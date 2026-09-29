<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\InvalidTimeRange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * Intervalo de tiempo semiabierto [inicio, fin).
 *
 * Decisiones de diseno:
 *  - Es INMUTABLE y se normaliza a UTC. Todo el sistema trabaja en UTC; la
 *    conversion a zona local se hace solo en la presentacion.
 *  - Es SEMIABIERTO porque dos citas consecutivas (10:00-10:30 y 10:30-11:00)
 *    comparten el instante 10:30 pero NO se traslapan. El traslape se define
 *    como:  inicio_A < fin_B  &&  inicio_B < fin_A.
 *  - Encapsula la validacion de duracion minima/maxima, que de otro modo se
 *    repetiria en cada endpoint.
 */
final readonly class TimeRange
{
    public const MIN_DURATION_MINUTES = 10;
    public const MAX_DURATION_MINUTES = 480;

    private function __construct(
        private CarbonImmutable $start,
        private CarbonImmutable $end,
    ) {
    }

    /**
     * Construye un intervalo validando unicamente que el fin sea posterior al inicio.
     */
    public static function from(
        CarbonInterface|\DateTimeInterface|string $start,
        CarbonInterface|\DateTimeInterface|string $end,
    ): self {
        $startAt = self::normalise($start);
        $endAt = self::normalise($end);

        $range = new self($startAt, $endAt);

        if ($endAt->lessThanOrEqualTo($startAt)) {
            throw InvalidTimeRange::endBeforeStart($range);
        }

        return $range;
    }

    /**
     * Intervalo para una cita: aplica ademas la duracion maxima/minima.
     */
    public static function forAppointment(
        CarbonInterface|\DateTimeInterface|string $start,
        CarbonInterface|\DateTimeInterface|string $end,
    ): self {
        $range = self::from($start, $end);
        $minutes = $range->durationInMinutes();

        if ($minutes < self::MIN_DURATION_MINUTES || $minutes > self::MAX_DURATION_MINUTES) {
            throw InvalidTimeRange::durationOutOfRange(
                $range,
                $minutes,
                self::MIN_DURATION_MINUTES,
                self::MAX_DURATION_MINUTES,
            );
        }

        return $range;
    }

    /**
     * Determina si este intervalo se traslapa con otro.
     *
     * La condicion es estrictamente correcta para intervalos semiabiertos:
     *   traslape <=> existe un instante comun
     *           <=> inicio_A < fin_B AND inicio_B < fin_A
     *
     * Casos limite:
     *   [10:00, 10:30) vs [10:30, 11:00) -> false (se tocan, no se traslapan)
     *   [10:00, 10:30) vs [10:29, 11:00) -> true
     *   [10:00, 11:00) vs [10:00, 11:00) -> true
     */
    public function overlaps(self $other): bool
    {
        return $this->start->lessThan($other->end)
            && $other->start->lessThan($this->end);
    }

    /**
     * @param iterable<self> $ranges
     */
    public function overlapsAny(iterable $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->overlaps($range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param iterable<self> $ranges
     * @return list<self>
     */
    public function conflictsWith(iterable $ranges): array
    {
        $conflicts = [];
        foreach ($ranges as $range) {
            if ($this->overlaps($range)) {
                $conflicts[] = $range;
            }
        }

        return $conflicts;
    }

    /**
     * Devuelve un nuevo intervalo desplazado en el numero de minutos indicado.
     */
    public function shiftByMinutes(int $minutes): self
    {
        return new self(
            $this->start->addMinutes($minutes),
            $this->end->addMinutes($minutes),
        );
    }

    public function durationInMinutes(): int
    {
        return (int) round(($this->end->getTimestamp() - $this->start->getTimestamp()) / 60);
    }

    public function startAt(): CarbonImmutable
    {
        return $this->start;
    }

    public function endAt(): CarbonImmutable
    {
        return $this->end;
    }

    public function startsAtLocal(string $timezone): CarbonImmutable
    {
        return $this->start->setTimezone(new DateTimeZone($timezone));
    }

    /**
     * @return array{starts_at: string, ends_at: string}
     */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->start->toIso8601String(),
            'ends_at' => $this->end->toIso8601String(),
        ];
    }

    public function __toString(): string
    {
        return $this->start->toIso8601String().' -> '.$this->end->toIso8601String();
    }

    private static function normalise(
        CarbonInterface|\DateTimeInterface|string $value,
    ): CarbonImmutable {
        if (is_string($value)) {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        }

        if ($value instanceof CarbonImmutable) {
            return $value->utc();
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        return CarbonImmutable::instance(
            \DateTime::createFromInterface($value),
        )->utc();
    }
}
