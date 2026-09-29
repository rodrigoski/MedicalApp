<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\InvalidLicenseNumber;
use Stringable;

/**
 * Matricula profesional del medico.
 *
 * Se modela como value object (y no como string suelto) por la misma razon que
 * el documento del paciente: normalizar en un solo sitio evita duplicados
 * silenciosos ("CO-12345" vs "co12345").
 */
final readonly class LicenseNumber implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function from(string $value): self
    {
        $normalised = strtoupper((string) preg_replace('/[\s\-.]+/', '', trim($value)));

        if (strlen($normalised) < InvalidLicenseNumber::MIN_LENGTH
            || strlen($normalised) > InvalidLicenseNumber::MAX_LENGTH
            || preg_match('/^[A-Z0-9]+$/', $normalised) !== 1
        ) {
            throw InvalidLicenseNumber::because($value);
        }

        return new self($normalised);
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
