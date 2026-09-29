<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Contracts\DomainEventRepositoryInterface;
use Illuminate\Console\Command;

/**
 * Limpieza periodica de la outbox.
 *
 * Los eventos enviados hace mas de N dias ya cumplieron su funcion: se pueden
 * eliminar sin riesgo. Sin esta poda, la tabla `domain_events` crece de forma
 * lineal y arrastra el rendimiento (dimension "Rendimiento" de la auditoria).
 *
 * Solo se eliminan eventos YA DESPACHADOS: los pendientes nunca se borran,
 * aunque sean antiguos, porque son la unica garantia de entrega.
 */
final class PruneDomainEventsCommand extends Command
{
    protected $signature = 'events:prune {--days=90 : Antiguedad en dias de los eventos despachados}';

    protected $description = 'Elimina eventos de dominio ya entregados y antiguos.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now('UTC')->subDays($days);

        $deleted = \Illuminate\Support\Facades\DB::table('domain_events')
            ->whereNotNull('dispatched_at')
            ->where('occurred_at', '<', $cutoff)
            ->delete();

        $pending = app(DomainEventRepositoryInterface::class)->countPending();

        $this->info(sprintf(
            'Eventos eliminados: %d (anteriores a %s). Pendientes conservados: %d',
            $deleted,
            $cutoff->toDateString(),
            $pending,
        ));

        return self::SUCCESS;
    }
}
