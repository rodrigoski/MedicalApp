<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Abstraccion del reloj.
 *
 * Se inyecta en los servicios para que las pruebas puedan simular el paso del
 * tiempo (probar "no se agendan citas en el pasado" sin esperar al manana) y
 * para que el sistema sea determinista.
 */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;

    public function today(): \DateTimeImmutable;
}
