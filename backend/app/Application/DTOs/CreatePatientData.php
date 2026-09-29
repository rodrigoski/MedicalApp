<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\ValueObjects\DocumentId;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * DTO de entrada para crear un paciente.
 *
 * Los DTO viven en la capa de Aplicacion: son el contrato de los casos de uso,
 * NO el contrato de HTTP. Si manana un consumidor expone el mismo caso de uso
 * por GraphQL o por la cola, el DTO no cambia.
 */
final readonly class CreatePatientData
{
    /**
     * @param list<string> $allergies
     */
    public function __construct(
        public string $fullName,
        public DocumentId $documentId,
        public ?Email $email = null,
        public ?PhoneNumber $phone = null,
        public ?CarbonImmutable $birthDate = null,
        public ?string $gender = null,
        public ?string $address = null,
        public ?string $emergencyContactName = null,
        public ?PhoneNumber $emergencyContactPhone = null,
        public array $allergies = [],
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            fullName: (string) ($input['full_name'] ?? ''),
            documentId: DocumentId::from((string) ($input['document_id'] ?? '')),
            email: Email::tryFrom(isset($input['email']) ? (string) $input['email'] : null),
            phone: PhoneNumber::tryFrom(isset($input['phone']) ? (string) $input['phone'] : null),
            birthDate: isset($input['birth_date']) && $input['birth_date'] !== ''
                ? CarbonImmutable::parse((string) $input['birth_date'])->startOfDay()
                : null,
            gender: isset($input['gender']) ? (string) $input['gender'] : null,
            address: isset($input['address']) ? (string) $input['address'] : null,
            emergencyContactName: isset($input['emergency_contact_name'])
                ? (string) $input['emergency_contact_name']
                : null,
            emergencyContactPhone: PhoneNumber::tryFrom(
                isset($input['emergency_contact_phone'])
                    ? (string) $input['emergency_contact_phone']
                    : null,
            ),
            allergies: array_values(array_filter(
                array_map('strval', (array) ($input['allergies'] ?? [])),
            )),
        );
    }
}
