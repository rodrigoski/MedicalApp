<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Intento de modificar un agregado que ya fue persistido sin clave primaria
 * asignada (bug de programacion, no error de usuario).
 */
final class InvariantViolation extends DomainException
{
    private function __construct(string $message, private readonly string $invariant)
    {
        parent::__construct($message);
    }

    public static function missingIdentifier(string $entity): self
    {
        return new self(
            sprintf('No se puede guardar la entidad %s sin identificador asignado.', $entity),
            'missing_identifier',
        );
    }

    public function errorCode(): string
    {
        return 'domain.invariant_violated';
    }

    public function context(): array
    {
        return ['invariant' => $this->invariant];
    }
}
