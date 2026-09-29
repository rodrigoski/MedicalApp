<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * Raiz de todos los errores de negocio (capa de Dominio).
 *
 * Regla de dependencias: el Dominio NO conoce HTTP ni Laravel. Por eso estas
 * excepciones no tienen codigo de estado HTTP; el mapeo a respuestas vive en
 * App\Http\Exceptions\ExceptionMapper (capa de Presentacion).
 */
abstract class DomainException extends RuntimeException
{
    /**
     * Codigo estable y legible por maquina, parte del contrato publico de la API.
     */
    abstract public function errorCode(): string;

    /**
     * Contexto estructurado y seguro para logs (nunca contiene datos sensibles).
     *
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->errorCode(),
            'message' => $this->getMessage(),
            'context' => $this->context(),
        ];
    }
}
