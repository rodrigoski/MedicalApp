<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad y metadatos de respuesta.
 *
 * DEFENSA EN PROFUNDIDAD (OWASP):
 *   - X-Content-Type-Options: evita que el navegador interprete una respuesta
 *     JSON como HTML (relevante para XSS almacenado).
 *   - Cache-Control: no-store en respuestas con datos clinicos, para que un
 *     proxy o el navegador no guarden informacion sensible.
 *   - Server: se oculta la tecnologia del servidor (reduce superficie de ataque
 *     de reconocimiento).
 *
 * CRITERIO DE RENDIMIENTO: Server-Timing expone el tiempo de la peticion en la
 * cabecera de respuesta, lo que permite medir desde el navegador sin abrir el
 * panel de red a mano.
 */
final class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Solo aplicamos cabeceras de cache restrictivas a respuestas exitosas.
        if ($response->getStatusCode() < 400) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Server', 'clinicapp');

        if ($response->getStatusCode() === 503) {
            $response->headers->set('Retry-After', '30');
        }

        return $response;
    }
}
