<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Formato de matricula profesional invalido.
 */
final class InvalidLicenseNumber extends DomainException
{
    public const MIN_LENGTH = 4;
    public const MAX_LENGTH = 20;

    private function __construct(string $message, private readonly string $provided)
    {
        parent::__construct($message);
    }

    public static function because(string $value): self
    {
        return new self(
            sprintf(
                'La matricula "%s" no es valida. Se admiten de %d a %d caracteres alfanumericos.',
                $value,
                self::MIN_LENGTH,
                self::MAX_LENGTH,
            ),
            $value,
        );
    }

    public function errorCode(): string
    {
        return 'doctor.invalid_license_number';
    }

    public function context(): array
    {
        return [
            'min_length' => self::MIN_LENGTH,
            'max_length' => self::MAX_LENGTH,
            'provided_length' => strlen($this->provided),
        ];
    }
}
