<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * DTO de entrada para actualizar un paciente.
 *
 * Todos los campos son opcionales: solo se aplican los que llegan informados.
 * `changeXxx()` de la entidad se invoca unicamente para los datos presentes,
 * de modo que un PATCH parcial no borra informacion.
 */
final readonly class UpdatePatientData
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null    $allergies
     */
    private function __construct(
        public ?string $fullName = null,
        public ?Email $email = null,
        public ?PhoneNumber $phone = null,
        public ?string $address = null,
        public ?CarbonImmutable $birthDate = null,
        public ?string $gender = null,
        public ?string $emergencyContactName = null,
        public ?PhoneNumber $emergencyContactPhone = null,
        public ?array $allergies = null,
        public ?bool $isActive = null,
        public ?string $status = null,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            fullName: array_key_exists('full_name', $input) ? (string) $input['full_name'] : null,
            email: array_key_exists('email', $input) && $input['email'] !== null && $input['email'] !== ''
                ? Email::from((string) $input['email'])
                : null,
            phone: array_key_exists('phone', $input) && $input['phone'] !== null && $input['phone'] !== ''
                ? PhoneNumber::from((string) $input['phone'])
                : null,
            address: array_key_exists('address', $input) && $input['address'] !== null
                ? (string) $input['address']
                : null,
            birthDate: array_key_exists('birth_date', $input) && ! empty($input['birth_date'])
                ? CarbonImmutable::parse((string) $input['birth_date'])->startOfDay()
                : null,
            gender: array_key_exists('gender', $input) && $input['gender'] !== null
                ? (string) $input['gender']
                : null,
            emergencyContactName: array_key_exists('emergency_contact_name', $input)
                && $input['emergency_contact_name'] !== null
                    ? (string) $input['emergency_contact_name']
                    : null,
            emergencyContactPhone: array_key_exists('emergency_contact_phone', $input)
                && ! empty($input['emergency_contact_phone'])
                    ? PhoneNumber::from((string) $input['emergency_contact_phone'])
                    : null,
            // null = "no informes este campo"; [] = "borra todas las alergias".
            // La distincion importa: un PATCH parcial no puede borrar datos
            // clinicos que el cliente no pretendia tocar.
            allergies: array_key_exists('allergies', $input) && $input['allergies'] !== null
                ? array_values(array_filter(
                    array_map('strval', (array) $input['allergies']),
                    static fn (string $a): bool => trim($a) !== '',
                ))
                : null,
            isActive: array_key_exists('is_active', $input) && $input['is_active'] !== null
                ? (bool) $input['is_active']
                : null,
            status: array_key_exists('status', $input) && $input['status'] !== null
                ? (string) $input['status']
                : null,
            raw: $input,
        );
    }

    public function hasChanges(): bool
    {
        return $this->fullName !== null
            || $this->email !== null
            || $this->phone !== null
            || $this->address !== null
            || $this->birthDate !== null
            || $this->gender !== null
            || $this->emergencyContactName !== null
            || $this->emergencyContactPhone !== null
            || $this->allergies !== null
            || $this->isActive !== null
            || $this->status !== null;
    }
}
