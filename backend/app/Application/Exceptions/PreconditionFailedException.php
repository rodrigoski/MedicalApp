<?php

declare(strict_types=1);

namespace App\Application\Exceptions;

/**
 * El caso de uso se ejecuto correctamente pero el resultado no cumple las
 * precondiciones declaradas en el contrato. Es el equivalente a una
 * precondicion fallida (design by contract).
 */
final class PreconditionFailedException extends ApplicationException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message, 400, 'application.precondition_failed', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function noChangesProvided(): self
    {
        return new self(
            'No se envio ningun campo modificable; la operacion no tendria efecto.',
            ['hint' => 'Envie al menos un campo del recurso a actualizar.'],
        );
    }
}
