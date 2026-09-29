<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\InvalidEmail;
use Stringable;

/**
 * Direccion de correo electronico normalizada (minusculas, sin espacios).
 *
 * Al ser un value object, la normalizacion ocurre UNA sola vez y en un solo
 * lugar: la comparacion de duplicados en la base de datos y la validacion del
 * formato dejan de depender de como escribio el usuario el correo.
 */
final readonly class Email implements Stringable
{
    public const MAX_LENGTH = 254;

    private function __construct(private string $value)
    {
    }

    public static function from(string $value): self
    {
        $normalised = mb_strtolower(trim($value));

        if ($normalised === '' || mb_strlen($normalised) > self::MAX_LENGTH) {
            throw InvalidEmail::because($value);
        }

        if (filter_var($normalised, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidEmail::because($value);
        }

        return new self($normalised);
    }

    public static function tryFrom(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::from($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function domain(): string
    {
        return (string) substr(strrchr($this->value, '@'), 1);
    }

    /**
     * Parte local ("juan.perez" de "juan.perez@hospital.com").
     */
    public function localPart(): string
    {
        return (string) strstr($this->value, '@', true);
    }

    /**
     * Enmascara el correo para logs: j***@h***.com
     */
    public function masked(): string
    {
        [$local, $domain] = explode('@', $this->value, 2);

        return mb_substr($local, 0, 1)
            .'***@'
            .mb_substr($domain, 0, 1)
            .'***.'
            .implode('.', array_slice(explode('.', $domain), -1));
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
