<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Formato de correo electronico invalido.
 */
final class InvalidEmail extends DomainException
{
    private function __construct(string $message, private readonly string $provided)
    {
        parent::__construct($message);
    }

    public static function because(string $value): self
    {
        return new self(
            sprintf('El correo "%s" no tiene un formato valido.', $value),
            trim($value),
        );
    }

    public function errorCode(): string
    {
        return 'shared.invalid_email';
    }

    public function context(): array
    {
        return ['provided_length' => strlen($this->provided)];
    }
}
