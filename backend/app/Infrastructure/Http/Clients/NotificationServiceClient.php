<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Clients;

use App\Domain\Contracts\ClockInterface;
use App\Domain\Contracts\NotificationGatewayInterface;
use App\Domain\ValueObjects\DomainEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP del microservicio de notificaciones (FastAPI).
 *
 * TRATO IMPORTANTE: este cliente NUNCA propaga la excepcion hacia la capa de
 * aplicacion. Si el microservicio esta caido, devuelve false y el evento queda
 * pendiente en la outbox. Motivo: una caida del servicio de notificaciones no
 * puede hacer fallar una reserva de cita que ya se guardo en la base de datos.
 *
 * Medidas defensivas aplicadas:
 *   - Timeout de 4 s: evita que una peticion clinica se quede colgada.
 *   - 2 reintentos con backoff exponencial corto.
 *   - Cabecera de autenticacion por API key (comparacion constante en destino).
 *   - Log estructurado con el event_id para trazabilidad extremo a extremo.
 */
final class NotificationServiceClient implements NotificationGatewayInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 4,
        private readonly int $retries = 2,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function deliver(DomainEvent $event): bool
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(min(2, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
                ->retry($this->retries, static fn (int $attempt): int => 200 * $attempt)
                ->post($this->endpoint('/api/v1/events'), $event->toArray());
        } catch (ConnectionException $e) {
            Log::warning('notification_service.unreachable', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('notification_service.delivery_error', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->successful()) {
            Log::info('notification_service.delivered', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'status' => $response->status(),
            ]);

            return true;
        }

        Log::warning('notification_service.rejected', [
            'event_id' => $event->id,
            'event_type' => $event->type,
            'status' => $response->status(),
        ]);

        return false;
    }

    public function isReachable(): bool
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(2)
                ->get($this->endpoint('/api/v1/health'));
        } catch (\Throwable) {
            return false;
        }

        return $response->successful();
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
