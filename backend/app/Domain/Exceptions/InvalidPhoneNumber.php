<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Formato de numero de telefono invalido.
 */
final class InvalidPhoneNumber extends DomainException
{
    private function __construct(string $message, private readonly string $provided)
    {
        parent::__construct($message);
    }

    public const MIN_DIGITS = 7;
    public const MAX_DIGITS = 15;

    public static function because(string $value): self
    {
        return new self(
            sprintf(
                'El telefono "%s" no es valido: se esperan entre %d y %d digitos.',
                $value,
                self::MIN_DIGITS,
                self::MAX_DIGITS,
            ),
            $value,
        );
    }

    public function errorCode(): string
    {
        return 'shared.invalid_phone';
    }

    public function context(): array
    {
        return [
            'min_digits' => self::MIN_DIGITS,
            'max_digits' => self::MAX_DIGITS,
            'provided_length' => strlen($this->provided),
        ];
    }
}
