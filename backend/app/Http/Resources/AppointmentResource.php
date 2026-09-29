<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Entities\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representacion JSON de una cita.
 *
 * Expone el intervalo anidado (`time_range`) ademas de `starts_at`/`ends_at`
 * planos, y el estado con su etiqueta legible: el cliente no tiene que
 * duplicar la tabla de estados ni el formato de fecha.
 *
 * @property-read Appointment $resource
 */
final class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $appointment = $this->resource;
        $range = $appointment->timeRange();

        return [
            'id' => $appointment->id(),
            'patient_id' => $appointment->patientId(),
            'doctor_id' => $appointment->doctorId(),
            'status' => $appointment->status()->value,
            'status_label' => $appointment->status()->label(),
            'occupies_schedule' => $appointment->occupiesSchedule(),
            'time_range' => [
                'starts_at' => $range->startAt()->toIso8601String(),
                'ends_at' => $range->endAt()->toIso8601String(),
                'duration_minutes' => $range->durationInMinutes(),
            ],
            'starts_at' => $range->startAt()->toIso8601String(),
            'ends_at' => $range->endAt()->toIso8601String(),
            'duration_minutes' => $range->durationInMinutes(),
            'reason' => $appointment->reason(),
            'notes' => $appointment->notes(),
            'confirmed_at' => $appointment->confirmedAt()?->toIso8601String(),
            'cancelled_at' => $appointment->cancelledAt()?->toIso8601String(),
            'cancellation_reason' => $appointment->cancellationReason(),
            'created_at' => $appointment->createdAt()->toIso8601String(),
            'updated_at' => $appointment->updatedAt()->toIso8601String(),
        ];
    }
}
