<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\ApiResponse;
use App\Support\ApiKeys;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticacion por API key para las operaciones de escritura.
 *
 * CRITERIO DE SEGURIDAD (OWASP API2 - Broken Authentication):
 *   - Comparacion en TIEMPO CONSTANTE con hash_equals(). Un `===` sobre cadenas
 *     permite un ataque de temporizacion que revela el prefijo correcto.
 *   - La clave se lee de la cabecera X-Api-Key, nunca de la query string: las
 *     URLs quedan en los logs del servidor y del proxy.
 *   - Las claves admiten varias (rotacion sin caida) separadas por coma.
 *   - El cuerpo NUNCA se registra: puede contener datos clinicos.
 *
 * NOTA ARQUITECTONICA: este middleware pertenece a la capa de Presentacion.
 * Las capas inferiores no saben que existen estas cabeceras.
 */
final class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('X-Api-Key');

        if ($provided === null || $provided === '') {
            return ApiResponse::error(
                'Falta la cabecera X-Api-Key.',
                'authentication.api_key_missing',
                401,
            );
        }

        $valid = ApiKeys::anyValid($provided);

        if (! $valid) {
            return ApiResponse::error(
                'La API key proporcionada no es valida.',
                'authentication.api_key_invalid',
                401,
            );
        }

        return $next($request);
    }
}
