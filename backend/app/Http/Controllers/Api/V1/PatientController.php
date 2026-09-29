<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\PatientService;
use App\Http\Controllers\ApiController;
use App\Http\Requests\ListQueryRequest;
use App\Http\Requests\StorePatientRequest;
use App\Http\Requests\UpdatePatientRequest;
use App\Http\Resources\PatientResource;
use App\Http\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * CRUD de pacientes (capa de presentacion).
 *
 * Observa el patron unico de la arquitectura:
 *   1. FormRequest: validacion de FORMATO.
 *   2. Servicio: caso de uso y reglas de NEGOCIO.
 *   3. Recurso: forma de la RESPUESTA.
 *
 * El controlador NO contiene reglas de negocio ni SQL. Es un adaptador: si se
 * reemplaza REST por GraphQL, este archivo se reescribe y nada mas.
 */
final class PatientController extends ApiController
{
    public function __construct(
        private readonly PatientService $patients,
    ) {
    }

    /**
     * GET /api/v1/patients
     */
    public function index(ListQueryRequest $request): JsonResponse
    {
        $result = $this->patients->list($request->toQuery());

        return ApiResponse::paginated($result);
    }

    /**
     * POST /api/v1/patients
     */
    public function store(StorePatientRequest $request): JsonResponse
    {
        $patient = $this->patients->create($request->toData());

        return $this->created([
            'patient' => (new PatientResource($patient))->resolve($request),
        ]);
    }

    /**
     * GET /api/v1/patients/{id}
     */
    public function show(int $id): JsonResponse
    {
        $patient = $this->patients->find($id);

        return $this->ok([
            'patient' => (new PatientResource($patient))->resolve(request()),
        ]);
    }

    /**
     * PATCH /api/v1/patients/{id}
     */
    public function update(UpdatePatientRequest $request, int $id): JsonResponse
    {
        $patient = $this->patients->update($id, $request->toData());

        return $this->ok([
            'patient' => (new PatientResource($patient))->resolve($request),
        ]);
    }

    /**
     * DELETE /api/v1/patients/{id}
     *
     * Borrado logico: el registro se conserva (auditoria clinica) y se marca
     * `deleted_at`. Nunca se ejecuta un DELETE fisico sobre datos de pacientes.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->patients->delete($id);

        return $this->deleted();
    }
}
