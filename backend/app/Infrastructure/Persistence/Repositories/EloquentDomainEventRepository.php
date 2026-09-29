<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\DomainEventRepositoryInterface;
use App\Domain\ValueObjects\DomainEvent;
use App\Infrastructure\Persistence\Models\DomainEventModel;
use Carbon\CarbonImmutable;

/**
 * Repositorio de la outbox (tabla domain_events).
 *
 * Patron OUTBOX: los eventos se escriben en la misma transaccion que el cambio
 * de negocio. Esta clase no envia nada: solo guarda y consulta. El envio lo hace
 * EventPublisher, en un proceso separado.
 */
final class EloquentDomainEventRepository implements DomainEventRepositoryInterface
{
    public function record(DomainEvent $event): void
    {
        DomainEventModel::query()->create([
            'event_id' => $event->id,
            'event_type' => $event->type,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
            'payload' => $event->payload,
            'occurred_at' => $event->occurredAt,
            'attempts' => 0,
        ]);
    }

    /**
     * @return list<DomainEvent>
     */
    public function pending(int $limit = 50): array
    {
        return DomainEventModel::query()
            ->pending()
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get()
            ->map(static fn (DomainEventModel $m): DomainEvent => self::toDomainEvent($m))
            ->all();
    }

    public function markDispatched(string $eventId, string $dispatchedAt): void
    {
        DomainEventModel::query()
            ->where('event_id', $eventId)
            ->update([
                'dispatched_at' => $dispatchedAt,
                'attempts' => \Illuminate\Support\Facades\DB::raw('attempts + 1'),
                'last_error' => null,
            ]);
    }

    public function markFailed(string $eventId, string $error): void
    {
        // El error se trunca a 500 caracteres: la columna es un varchar(500) y
        // ademas no queremos volcar respuestas completas del otro servicio.
        DomainEventModel::query()
            ->where('event_id', $eventId)
            ->update([
                'attempts' => \Illuminate\Support\Facades\DB::raw('attempts + 1'),
                'last_error' => mb_substr($error, 0, 500),
            ]);
    }

    public function countPending(): int
    {
        return DomainEventModel::query()->pending()->count();
    }

    public function countTotal(): int
    {
        return DomainEventModel::query()->count();
    }

    private static function toDomainEvent(DomainEventModel $model): DomainEvent
    {
        return new DomainEvent(
            id: (string) $model->event_id,
            type: (string) $model->event_type,
            aggregateType: (string) $model->aggregate_type,
            aggregateId: (int) $model->aggregate_id,
            payload: (array) $model->payload,
            occurredAt: CarbonImmutable::instance($model->occurred_at)->toIso8601String(),
            dispatchedAt: $model->dispatched_at !== null
                ? CarbonImmutable::instance($model->dispatched_at)->toIso8601String()
                : null,
            attempts: (int) $model->attempts,
            lastError: $model->last_error,
        );
    }
}
