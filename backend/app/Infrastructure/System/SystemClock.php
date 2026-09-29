<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Domain\Contracts\ClockInterface;
use Carbon\CarbonImmutable;

/**
 * Implementacion real del reloj (UTC).
 *
 * Toda la aplicacion trabaja en UTC: las zonas horarias se aplican solo en la
 * capa de presentacion. Asi las comparaciones de intervalos nunca dependen de
 * daylight saving time ni de la configuracion regional del servidor.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public function today(): \DateTimeImmutable
    {
        return CarbonImmutable::now('UTC')->startOfDay();
    }
}
