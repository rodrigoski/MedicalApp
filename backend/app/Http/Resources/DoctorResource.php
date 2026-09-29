<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Entities\Doctor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representacion JSON de un medico.
 *
 * Misma justificacion que PatientResource: la entidad de dominio no es el
 * contrato HTTP. Ver la nota en PatientResource.
 *
 * @property-read Doctor $resource
 */
final class DoctorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $doctor = $this->resource;

        return [
            'id' => $doctor->id(),
            'full_name' => $doctor->fullName(),
            'license_number' => $doctor->licenseNumber()->value(),
            'specialty' => $doctor->specialty(),
            'email' => $doctor->email()?->value(),
            'phone' => $doctor->phone()?->value(),
            'status' => $doctor->status()->value,
            'status_label' => $doctor->status()->label(),
            'schedule' => [
                'start_hour' => $doctor->workingDayStartHour(),
                'end_hour' => $doctor->workingDayEndHour(),
                'slot_minutes' => $doctor->slotDurationMinutes(),
            ],
            'created_at' => $doctor->createdAt()->toIso8601String(),
            'updated_at' => $doctor->updatedAt()->toIso8601String(),
        ];
    }
}
