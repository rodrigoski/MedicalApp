<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\DoctorService;
use App\Http\Controllers\ApiController;
use App\Http\Requests\ListQueryRequest;
use App\Http\Requests\StoreDoctorRequest;
use App\Http\Requests\UpdateDoctorRequest;
use App\Http\Resources\DoctorResource;
use App\Http\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class DoctorController extends ApiController
{
    public function __construct(
        private readonly DoctorService $doctors,
    ) {
    }

    /**
     * GET /api/v1/doctors
     */
    public function index(ListQueryRequest $request): JsonResponse
    {
        $result = $this->doctors->list($request->toQuery());

        return ApiResponse::paginated($result);
    }

    /**
     * POST /api/v1/doctors
     */
    public function store(StoreDoctorRequest $request): JsonResponse
    {
        $doctor = $this->doctors->create($request->toData());

        return $this->created([
            'doctor' => (new DoctorResource($doctor))->resolve($request),
        ]);
    }

    /**
     * GET /api/v1/doctors/{id}
     */
    public function show(int $id): JsonResponse
    {
        $doctor = $this->doctors->find($id);

        return $this->ok([
            'doctor' => (new DoctorResource($doctor))->resolve(request()),
        ]);
    }

    /**
     * PATCH /api/v1/doctors/{id}
     */
    public function update(UpdateDoctorRequest $request, int $id): JsonResponse
    {
        $doctor = $this->doctors->update($id, $request->toData());

        return $this->ok([
            'doctor' => (new DoctorResource($doctor))->resolve($request),
        ]);
    }

    /**
     * DELETE /api/v1/doctors/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $this->doctors->delete($id);

        return $this->deleted();
    }
}
