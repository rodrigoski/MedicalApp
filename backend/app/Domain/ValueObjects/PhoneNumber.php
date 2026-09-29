<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\InvalidPhoneNumber;
use Stringable;

/**
 * Numero telefonico normalizado a solo digitos, con prefijo internacional
 * opcional conservado aparte.
 *
 * Se normaliza aqui para que la comparacion y los mensajes (SMS) sean estables
 * sin importar como lo ingreso el usuario.
 */
final readonly class PhoneNumber implements Stringable
{
    private function __construct(
        private string $e164,
        private string $local,
    ) {
    }

    public static function from(string $value): self
    {
        $trimmed = trim($value);
        $hasPlus = str_starts_with($trimmed, '+');
        $digits = (string) preg_replace('/\D+/', '', $trimmed);

        $length = strlen($digits);
        if ($length < InvalidPhoneNumber::MIN_DIGITS || $length > InvalidPhoneNumber::MAX_DIGITS) {
            throw InvalidPhoneNumber::because($value);
        }

        // Colombia (57) y Venezuela (58) usan 10 digitos; el resto entre 7 y 15.
        $e164 = ($hasPlus ? '+' : '').$digits;

        /*
         * Numero local = solo el nacional. Se separa el codigo de pais cuando
         * la longitud indica que viene en formato internacional (E.164), para
         * que "+57 300 123 4567" y "3001234567" produzcan el MISMO local. Sin
         * esta normalizacion, el mismo numero se guardaria de dos formas y los
         * indices unicos de la BD dejarian de proteger contra duplicados.
         */
        $local = strlen($digits) > 10 ? substr($digits, 2) : $digits;

        return new self($e164, $local);
    }

    public static function tryFrom(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::from($value);
    }

    /**
     * Formato internacional (+573001234567).
     */
    public function value(): string
    {
        return $this->e164;
    }

    /**
     * Solo digitos, sin prefijo (3001234567).
     */
    public function local(): string
    {
        return $this->local;
    }

    public function equals(self $other): bool
    {
        return $this->e164 === $other->e164;
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
