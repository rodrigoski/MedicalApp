<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Services\EventPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job que entrega los eventos pendientes al microservicio de notificaciones.
 *
 * POR QUE ES UN JOB Y NO UNA LLAMADA DIRECTA:
 * la peticion HTTP de un usuario (crear cita) no debe esperar a que el
 * microservicio responda. Si responde, todo bien; si esta caido, el evento
 * queda en la outbox y este job lo reintenta mas tarde. La reserva del paciente
 * nunca se pierde por una dependencia externa.
 *
 * `$tries = 3` con backoff: evita Martillo Infinite Loop (crítica de la
 * dimension "Rendimiento").
 */
final class DeliverDomainEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 45];

    public int $timeout = 30;

    /**
     * @var list<array<string, mixed>>
     */
    public array $failedJobs = [];

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(EventPublisher $publisher): void
    {
        $result = $publisher->dispatchPending(50);

        if ($result['failed'] > 0) {
            Log::warning('events.delivery_partial', $result);
        }

        // Si quedan pendientes (por que el limite de 50 no los cubrio), se
        // reencola el mismo job para draining progresivo de la cola.
        if ($result['pending'] > 0 && $result['delivered'] > 0) {
            self::dispatch()->delay(now()->addSeconds(5));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('events.delivery_failed', [
            'error' => $exception->getMessage(),
            'hint' => 'Los eventos siguen en la tabla domain_events; ejecute: php artisan events:dispatch',
        ]);
    }
}
