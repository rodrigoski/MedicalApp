<?php

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * Error de la capa de Aplicacion: la peticion es valida desde HTTP pero no se
 * puede completar por una regla de negocio o por la integracion con otro
 * sistema.
 *
 * A diferencia de DomainException, esta excepcion SI tiene semantica HTTP
 * porque la capa de aplicacion ya no es agnostica: conoce casos de uso
 * completos, no invariantes atomicas.
 */
class ApplicationException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 500,
        private readonly string $errorCode = 'application.error',
        private readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function operationFailed(string $message, array $context = []): self
    {
        return new self($message, 500, 'application.operation_failed', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function externalServiceUnavailable(string $service, array $context = []): self
    {
        return new self(
            sprintf('El servicio "%s" no esta disponible en este momento.', $service),
            503,
            'application.service_unavailable',
            ['service' => $service] + $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function notImplemented(string $feature): self
    {
        return new self(
            sprintf('La funcionalidad "%s" no esta implementada.', $feature),
            501,
            'application.not_implemented',
            ['feature' => $feature],
        );
    }
}
