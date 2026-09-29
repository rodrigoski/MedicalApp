<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registro de peticiones con identificador de trazabilidad.
 *
 * Cada peticion recibe un `X-Request-Id`. El mismo identificador viaja en los
 * logs del contenedor, en la cabecera de la respuesta y en el cuerpo JSON, de
 * modo que se puede correlacionar el error que ve el usuario con la linea exacta
 * del log y con el evento que llego al microservicio de notificaciones.
 *
 * CRITERIO DE SEGURIDAD: no se registra el cuerpo de la peticion ni cabeceras
 * sensibles (X-Api-Key, Authorization, Cookie). Solo metadatos.
 *
 * ORDEN: se antepone al grupo `api` (ver bootstrap/app.php) para que tambien
 * trace los rechazos de autenticacion. Un 401 es justo el evento que mas
 * necesita identificador y es el que mas se pierde cuando el logger corre
 * despues del guardia.
 */
final class RequestLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = RequestId::resolve($request);

        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        $status = $response->getStatusCode();

        $context = [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'status' => $status,
            'duration_ms' => $durationMs,
            'ip' => $request->ip(),
        ];

        if ($status >= 500) {
            Log::error('http.request', $context);
        } elseif ($status >= 400) {
            Log::warning('http.request', $context);
        } else {
            Log::info('http.request', $context);
        }

        $response->headers->set(RequestId::HEADER, $requestId);
        $response->headers->set('Server-Timing', sprintf('app;dur=%s', $durationMs));

        return $response;
    }
}
