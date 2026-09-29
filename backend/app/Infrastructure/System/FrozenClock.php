<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Domain\Contracts\ClockInterface;
use Carbon\CarbonImmutable;

/**
 * Reloj simulado para pruebas.
 *
 * Permite verificar reglas dependientes del tiempo ("no se agendan citas en el
 * pasado", "la jornada es de 8 a 18") sin esperar en el tiempo real ni usar
 * dobles de framework.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(
        private CarbonImmutable $moment,
    ) {
    }

    public static function at(string $iso8601): self
    {
        return new self(CarbonImmutable::parse($iso8601, 'UTC')->utc());
    }

    public function now(): \DateTimeImmutable
    {
        return $this->moment;
    }

    public function today(): \DateTimeImmutable
    {
        return $this->moment->startOfDay();
    }

    public function advanceMinutes(int $minutes): void
    {
        $this->moment = $this->moment->addMinutes($minutes);
    }

    public function advanceDays(int $days): void
    {
        $this->moment = $this->moment->addDays($days);
    }
}
