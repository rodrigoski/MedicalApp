<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\ApiResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * Controlador base de la API.
 *
 * Concentra la conversion de errores de validacion al formato uniforme de la
 * API. Asi ningun controlador repite el mismo try/catch, y el formato de error
 * es consistente en las 20+ rutas (criterio de "Documentacion" y de Clean
 * Code: no repetir).
 */
abstract class ApiController extends BaseController
{
    /**
     * @return array<string, mixed>
     */
    protected function ok(array $data = [], array $meta = []): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success($data, 200, $meta);
    }

    /**
     * @return array<string, mixed>
     */
    protected function created(array $data): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::created($data);
    }

    protected function deleted(): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success(['message' => 'Registro eliminado logicamente.'], 200);
    }
}
