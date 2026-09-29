<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Services\EventPublisher;
use Illuminate\Console\Command;

/**
 * Entrega manual de los eventos acumulados en la outbox.
 *
 * El servicio `queue-worker` de docker-compose lo hace automaticamente. Este
 * comando existe para:
 *   - depurar cuando el microservicio estuvo caido;
 *   - reintentar sin reiniciar el stack;
 *   - mostrar la evidencia del patron outbox durante la exposicion.
 */
final class DispatchDomainEventsCommand extends Command
{
    protected $signature = 'events:dispatch {--limit=50 : Maximo de eventos a entregar} {--dry-run : Solo mostrar}';

    protected $description = 'Entrega los eventos pendientes al microservicio de notificaciones.';

    public function handle(EventPublisher $publisher): int
    {
        if ($this->option('dry-run')) {
            $diagnostics = $publisher->diagnostics();
            $this->table(
                ['Metrica', 'Valor'],
                [
                    ['Eventos totales', (string) ($diagnostics['total_events'] ?? 0)],
                    ['Eventos pendientes', (string) ($diagnostics['pending_events'] ?? 0)],
                    ['Microservicio accesible', ($diagnostics['gateway_reachable'] ?? false) ? 'si' : 'no'],
                ],
            );

            return self::SUCCESS;
        }

        $result = $publisher->dispatchPending((int) $this->option('limit'));

        $this->info(sprintf(
            'Entregados: %d | Fallidos: %d | Pendientes restantes: %d',
            $result['delivered'],
            $result['failed'],
            $result['pending'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
