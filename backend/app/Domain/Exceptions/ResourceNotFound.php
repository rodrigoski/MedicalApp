<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * El agregado solicitado no existe (o fue eliminado logicamente).
 */
final class ResourceNotFound extends DomainException
{
    /**
     * @param array<string, scalar|null> $context
     */
    private function __construct(
        string $message,
        private readonly string $resource,
        private readonly string $identifier,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function patient(int $id): self
    {
        return new self(
            sprintf('El paciente %d no existe o fue eliminado.', $id),
            'patient',
            (string) $id,
            ['patient_id' => $id],
        );
    }

    public static function doctor(int $id): self
    {
        return new self(
            sprintf('El medico %d no existe o esta inactivo.', $id),
            'doctor',
            (string) $id,
            ['doctor_id' => $id],
        );
    }

    public static function appointment(int $id): self
    {
        return new self(
            sprintf('La cita %d no existe.', $id),
            'appointment',
            (string) $id,
            ['appointment_id' => $id],
        );
    }

    public function errorCode(): string
    {
        return $this->resource.'.not_found';
    }

    public function context(): array
    {
        return ['resource' => $this->resource, 'id' => $this->identifier] + $this->context;
    }
}
