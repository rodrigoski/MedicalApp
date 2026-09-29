<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\ValueObjects\PagedResult;
use Illuminate\Http\JsonResponse;

/**
 * Forma unica de las respuestas de la API.
 *
 * Decisiones de diseño (contrato estable para el cliente):
 *   - Envoltura `data` / `meta` / `error`: el cliente siempre sabe donde mirar.
 *   - Toda respuesta lleva `request_id`, exito y error por igual. Es lo que
 *     permite que quien reporta un fallo entregue un identificador en lugar de
 *     una aproximacion por hora. Ver RequestId.
 *   - Los errores siguen la forma de RFC 9457 (problem details) simplificada,
 *     con `code` estable para tratarlo programaticamente y `detail` en espanol
 *     para mostrar al usuario.
 *   - Las fechas se emiten en ISO-8601 con zona explicita (Z), porque todo el
 *     sistema trabaja en UTC.
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function success(
        array $data = [],
        int $status = 200,
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'data' => $data,
            // El identificador viaja en el cuerpo y no solo en la cabecera para
            // que un cliente que solo mira el JSON pueda reportarlo. Ver
            // RequestId para el criterio de generacion y propagacion.
            'request_id' => RequestId::current(),
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function created(array $data, array $extra = []): JsonResponse
    {
        return self::success($data, 201, $extra);
    }

    public static function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    /**
     * @param PagedResult<mixed> $result
     * @param array<string, mixed> $extra
     */
    public static function paginated(PagedResult $result, array $extra = []): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $result->items,
            'meta' => [
                'pagination' => $result->meta(),
            ] + $extra,
            'request_id' => RequestId::current(),
        ];

        return new JsonResponse($payload, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(
        string $detail,
        string $code = 'error',
        int $status = 400,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'detail' => $detail,
                'status' => $status,
            ],
            'request_id' => RequestId::current(),
        ];

        if ($details !== []) {
            $payload['error']['context'] = $details;
        }

        return new JsonResponse($payload, $status, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Error de validacion: `context.errors` lleva el mapa campo -> mensajes,
     * igual que hace Laravel, para no romper a los clientes que ya lo esperan.
     *
     * @param array<string, list<string>> $errors
     */
    public static function validationError(string $detail, array $errors): JsonResponse
    {
        return self::error(
            $detail,
            'validation.failed',
            422,
            ['errors' => $errors],
        );
    }
}
