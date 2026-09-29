<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `domain_events` (outbox).
 *
 * Se escribe DENTRO de la transaccion de negocio. Por eso, o se guardan ambos
 * o no se guarda ninguno: nunca queda una cita creada sin su evento, ni un
 * evento de algo que no ocurrio.
 */
final class DomainEventModel extends Model
{
    protected $table = 'domain_events';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'occurred_at',
        'dispatched_at',
        'attempts',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'aggregate_id' => 'integer',
            'attempts' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<DomainEventModel>  $query
     * @return Builder<DomainEventModel>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('dispatched_at')
            ->where('attempts', '<', 10);
    }
}
