<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\DTOs\RescheduleAppointmentData;
use App\Application\Services\AppointmentService;
use App\Application\Services\AvailabilityService;
use App\Http\Controllers\ApiController;
use App\Http\Requests\ListQueryRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentStatusRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\AvailabilityResource;
use App\Http\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD y transiciones de estado de citas.
 *
 * El controlador es el lugar donde se ve con mayor claridad por que la regla de
 * no traslape NO esta aqui: este archivo no tiene ninguna consulta a la base de
 * datos. Solo valida formato, invoca el caso de uso y formatea la respuesta.
 */
final class AppointmentController extends ApiController
{
    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly AvailabilityService $availability,
    ) {
    }

    /**
     * GET /api/v1/appointments
     */
    public function index(ListQueryRequest $request): JsonResponse
    {
        $result = $this->appointments->list($request->toQuery());

        return ApiResponse::paginated($result);
    }

    /**
     * POST /api/v1/appointments
     *
     * REGLA DE NEGOCIO APLICADA AQUI (por el caso de uso, no por este codigo):
     * devuelve 409 si el horario se traslapa con otra cita del mismo medico o
     * del mismo paciente.
     */
    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $appointment = $this->appointments->book($request->toData());

        return $this->created([
            'appointment' => (new AppointmentResource($appointment))->resolve($request),
        ]);
    }

    /**
     * GET /api/v1/appointments/{id}
     */
    public function show(int $id): JsonResponse
    {
        $appointment = $this->appointments->find($id);

        return $this->ok([
            'appointment' => (new AppointmentResource($appointment))->resolve(request()),
        ]);
    }

    /**
     * PATCH /api/v1/appointments/{id}/status
     *
     * Maquina de estados:
     *   pending   -> confirmed | cancelled
     *   confirmed -> cancelled | pending
     *   cancelled -> (terminal)
     */
    public function updateStatus(UpdateAppointmentStatusRequest $request, int $id): JsonResponse
    {
        $appointment = $this->appointments->changeStatus($id, $request->toData());

        return $this->ok([
            'appointment' => (new AppointmentResource($appointment))->resolve($request),
        ]);
    }

    /**
     * POST /api/v1/appointments/{id}/reschedule
     *
     * Reutiliza EXACTAMENTE la misma validacion de traslape que el alta: por eso
     * reprogramar no puede "colarse" un choque de horarios.
     */
    public function reschedule(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'duration_minutes' => ['nullable', 'integer', 'between:10,480'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'starts_at.required' => 'Debe indicar el nuevo inicio de la cita.',
            'starts_at.date' => 'La nueva fecha/hora no es valida (use ISO-8601).',
        ]);

        $data = RescheduleAppointmentData::fromArray($validated);
        $appointment = $this->appointments->reschedule($id, $data);

        return $this->ok([
            'appointment' => (new AppointmentResource($appointment))->resolve($request),
        ]);
    }

    /**
     * DELETE /api/v1/appointments/{id}?reason=...
     *
     * Atajo semantico de "cancelar". Se mantiene separado del DELETE fisico
     * porque en una clinica nada se borra: una cita se cancela con motivo.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Debe indicar el motivo de la cancelacion.',
        ]);

        $appointment = $this->appointments->cancel($id, (string) $validated['reason']);

        return $this->ok([
            'appointment' => (new AppointmentResource($appointment))->resolve($request),
            'message' => 'La cita fue cancelada; el horario quedo disponible.',
        ]);
    }

    /**
     * GET /api/v1/doctors/{doctorId}/availability?date=YYYY-MM-DD
     */
    public function availability(Request $request, int $doctorId): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'slot_minutes' => ['nullable', 'integer', 'between:5,240'],
        ], [
            'date.required' => 'Debe indicar la fecha en formato YYYY-MM-DD.',
            'date.date_format' => 'La fecha debe tener el formato YYYY-MM-DD.',
        ]);

        $result = $this->availability->forDoctorOnDate(
            doctorId: $doctorId,
            date: (string) $validated['date'],
            slotMinutes: isset($validated['slot_minutes']) ? (int) $validated['slot_minutes'] : null,
        );

        return $this->ok([
            'availability' => (new AvailabilityResource($result))->resolve($request),
        ]);
    }
}
