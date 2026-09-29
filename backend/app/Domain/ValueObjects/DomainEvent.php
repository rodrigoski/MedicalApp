<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Evento de dominio inmutable.
 *
 * Es la unidad de integracion con el microservicio de notificaciones. Se persiste
 * primero en la tabla `domain_events` (patron outbox) y despues se entrega, de
 * modo que una caida del microservicio NO pierde informacion de negocio.
 */
final readonly class DomainEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $aggregateType,
        public int $aggregateId,
        public array $payload,
        public string $occurredAt,
        public ?string $dispatchedAt = null,
        public int $attempts = 0,
        public ?string $lastError = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function make(
        string $type,
        string $aggregateType,
        int $aggregateId,
        array $payload,
        string $occurredAt,
    ): self {
        return new self(
            id: self::uuidV4(),
            type: $type,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payload: $payload,
            occurredAt: $occurredAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->id,
            'event_type' => $this->type,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'occurred_at' => $this->occurredAt,
            'payload' => $this->payload,
        ];
    }

    public function isDispatched(): bool
    {
        return $this->dispatchedAt !== null;
    }

    /**
     * Genera un UUID v4 usando el generador criptografico del sistema.
     */
    public static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
