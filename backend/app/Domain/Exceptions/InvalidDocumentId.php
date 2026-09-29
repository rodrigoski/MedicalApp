<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * El valor recibido no cumple el formato del documento de identidad.
 */
final class InvalidDocumentId extends DomainException
{
    private function __construct(string $message, private readonly string $provided)
    {
        parent::__construct($message);
    }

    public static function because(string $value): self
    {
        $normalised = self::normalise($value);

        return new self(
            sprintf(
                'El documento "%s" no es valido. Se admiten de %d a %d caracteres alfanumericos '
                . '(se ignoran guiones y espacios; ej. "CC-1.234.567" o "AB1234CD").',
                $value,
                self::MIN_LENGTH,
                self::MAX_LENGTH,
            ),
            $normalised,
        );
    }

    public const MIN_LENGTH = 5;
    public const MAX_LENGTH = 20;

    public static function normalise(string $value): string
    {
        return strtoupper(
            (string) preg_replace('/[\s\-.]+/', '', trim($value)),
        );
    }

    public function errorCode(): string
    {
        return 'patient.invalid_document_id';
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
