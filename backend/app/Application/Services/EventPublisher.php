<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Contracts\ClockInterface;
use App\Domain\Contracts\DoctorRepositoryInterface;
use App\Domain\Contracts\DomainEventRepositoryInterface;
use App\Domain\Contracts\NotificationGatewayInterface;
use App\Domain\ValueObjects\DomainEvent;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Publicador de eventos de dominio hacia el microservicio de notificaciones.
 *
 * CONCEPTO CLAVE: PATRON OUTBOX.
 *
 * El problema que resuelve: si, dentro de la transaccion que crea una cita,
 * se llama por HTTP al microservicio y ese servicio se cae, se pierde la
 * notificacion O se revierte una reserva que si fue guardada. Ninguna de las dos
 * opciones es aceptable en un sistema clinico.
 *
 * La solucion en dos fases:
 *   1. En la MISMA transaccion se escribe el evento en la tabla domain_events
 *      (eso ya lo hace cada caso de uso a traves de DomainEventRepository).
 *   2. Un proceso aparte (el comando events:dispatch o el job en cola) toma los
 *      eventos pendientes y los entrega. Si falla, quedan pendientes y se
 *      reintentan. Ninguna perdida, ninguna acoplamiento en la transaccion.
 */
final class EventPublisher
{
    public function __construct(
        private readonly DomainEventRepositoryInterface $events,
        private readonly NotificationGatewayInterface $gateway,
        private readonly ClockInterface $clock,
        private readonly Dispatcher $bus,
    ) {
    }

    /**
     * Entrega los eventos pendientes al microservicio.
     *
     * @return array{delivered: int, failed: int, pending: int}
     */
    public function dispatchPending(int $limit = 50): array
    {
        $pending = $this->events->pending($limit);

        $delivered = 0;
        $failed = 0;

        foreach ($pending as $event) {
            if ($this->gateway->deliver($event)) {
                $this->events->markDispatched($event->id, $this->nowIso());
                $delivered++;

                continue;
            }

            $this->events->markFailed(
                $event->id,
                'El microservicio de notificaciones no respondio correctamente.',
            );
            $failed++;
        }

        return [
            'delivered' => $delivered,
            'failed' => $failed,
            'pending' => $this->events->countPending(),
        ];
    }

    /**
     * Encola la entrega en segundo plano (fire-and-forget desde la peticion HTTP).
     *
     * El usuario no espera al microservicio: la respuesta es inmediata y la
     * notificacion se entrega despues.
     */
    public function enqueueDelivery(): void
    {
        $this->bus->dispatch(new \App\Jobs\DeliverDomainEventsJob());
    }

    public function isGatewayReachable(): bool
    {
        return $this->gateway->isReachable();
    }

    public function pendingCount(): int
    {
        return $this->events->countPending();
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        return [
            'pending_events' => $this->events->countPending(),
            'total_events' => $this->events->countTotal(),
            'gateway_reachable' => $this->gateway->isReachable(),
        ];
    }

    /**
     * Reconstruye un DomainEvent desde la fila de la outbox. Util para depurar.
     */
    public static function describe(DomainEvent $event): string
    {
        return sprintf('[%s] %s#%d @ %s', $event->type, $event->aggregateType, $event->aggregateId, $event->occurredAt);
    }

    private function nowIso(): string
    {
        return \Carbon\CarbonImmutable::instance($this->clock->now())->toIso8601String();
    }
}
