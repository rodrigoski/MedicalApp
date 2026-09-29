<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Violacion de una restriccion de unicidad del negocio (documento, correo,
 * matricula profesional). Se traduce a HTTP 409.
 */
final class DuplicateResource extends DomainException
{
    /**
     * @param array<string, scalar|null> $context
     */
    private function __construct(
        string $message,
        private readonly string $resource,
        private readonly string $conflictCode,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function patientDocument(string $documentId): self
    {
        return new self(
            sprintf('Ya existe un paciente registrado con el documento "%s".', $documentId),
            'patient',
            'duplicate_document_id',
            ['document_id' => $documentId],
        );
    }

    public static function patientEmail(string $email): self
    {
        return new self(
            sprintf('Ya existe un paciente registrado con el correo "%s".', $email),
            'patient',
            'duplicate_email',
            ['email' => $email],
        );
    }

    public static function doctorLicense(string $license): self
    {
        return new self(
            sprintf('Ya existe un medico registrado con la matricula "%s".', $license),
            'doctor',
            'duplicate_license_number',
            ['license_number' => $license],
        );
    }

    public static function doctorEmail(string $email): self
    {
        return new self(
            sprintf('Ya existe un medico registrado con el correo "%s".', $email),
            'doctor',
            'duplicate_email',
            ['email' => $email],
        );
    }

    public function errorCode(): string
    {
        return $this->resource.'.'.$this->conflictCode;
    }

    public function context(): array
    {
        return ['resource' => $this->resource] + $this->context;
    }
}
