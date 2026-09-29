<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza que las respuestas de la API sean JSON.
 *
 * Sin esto, una excepcion no controlada en una ruta /api/* podria devolver la
 * pagina HTML de error de Laravel. El cliente (un movil, un frontend, otro
 * microservicio) recibiria HTML donde esperaba JSON.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
