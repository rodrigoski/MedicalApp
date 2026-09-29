<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\InvalidDocumentId;
use Stringable;

/**
 * Documento de identidad del paciente (CC, CE, NIT, PAS...).
 *
 * Normaliza a mayusculas sin guiones ni espacios para que "cc-1234567" y
 * "CC1234567" sean el mismo paciente. La unicidad se impone en la base de datos
 * con indice unico, no solo en PHP.
 */
final readonly class DocumentId implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function from(string $value): self
    {
        $normalised = self::normalise($value);

        if (strlen($normalised) < InvalidDocumentId::MIN_LENGTH
            || strlen($normalised) > InvalidDocumentId::MAX_LENGTH
            || preg_match('/^[A-Z0-9]+$/', $normalised) !== 1
        ) {
            throw InvalidDocumentId::because($value);
        }

        return new self($normalised);
    }

    /**
     * Normaliza el DOCUMENTO sin construir el value object.
     *
     * Existe separado de `from()` porque la capa HTTP necesita normalizar ANTES
     * de validar: si "cc-1234" se mandara crudo, la validacion de unicidad
     * compararia "cc-1234" contra "CC1234" y dejaria pasar un duplicado.
     */
    public static function normalise(string $value): string
    {
        return InvalidDocumentId::normalise($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
