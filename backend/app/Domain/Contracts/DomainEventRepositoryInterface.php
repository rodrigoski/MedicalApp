<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\ValueObjects\DomainEvent;

/**
 * "Outbox" de eventos de dominio.
 *
 * Se escribe DENTRO de la misma transaccion que el cambio de negocio. Gracias a
 * eso el sistema nunca pierde un evento por un fallo del microservicio: si la
 * entrega falla, el evento queda pendiente y se reintenta.
 */
interface DomainEventRepositoryInterface
{
    public function record(DomainEvent $event): void;

    /**
     * @return list<DomainEvent>
     */
    public function pending(int $limit = 50): array;

    public function markDispatched(string $eventId, string $dispatchedAt): void;

    public function markFailed(string $eventId, string $error): void;

    public function countPending(): int;

    public function countTotal(): int;
}
