<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Enums\AppointmentStatus;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validacion de entrada al agendar una cita.
 *
 * Lo que se valida AQUI (transporte):
 *   - Que las fechas sean validas y parseables.
 *   - Que el intervalo tenga sentido (fin > inicio) y la duracion este en rango.
 *   - Que el estado inicial sea uno de los permitidos.
 *
 * Lo que NO se valida aqui (negocio) y por que:
 *   - Que el paciente y el medico existan y esten activos.
 *   - Que NO haya traslape con otra cita.  <- AppointmentOverlapPolicy
 *   - Que la cita no este en el pasado. <- AppointmentOverlapPolicy
 *
 * Duplicar la regla de traslape en el FormRequest obligaria a hacer una
 * consulta a la base de datos desde la capa HTTP y, sobre todo, dejaria la
 * regla sin proteger cuando el caso de uso se invoca por otra via.
 */
final class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'min:1'],
            'doctor_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in([AppointmentStatus::PENDING->value, AppointmentStatus::CONFIRMED->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.date' => 'La fecha/hora de inicio no es valida (use formato ISO-8601).',
            'ends_at.after' => 'La cita debe terminar despues de iniciar.',
            'status.in' => 'El estado inicial solo puede ser "pending" o "confirmed".',
        ];
    }

    /**
     * Valida la duracion antes de que la regla llegue al dominio, para dar un
     * 422 descriptivo en lugar de un 422 generico de value object.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $range = TimeRange::forAppointment(
                    CarbonImmutable::parse((string) $this->input('starts_at')),
                    CarbonImmutable::parse((string) $this->input('ends_at')),
                );
            } catch (\App\Domain\Exceptions\InvalidTimeRange $e) {
                $validator->errors()->add('ends_at', $e->getMessage());

                return;
            }

            if ((int) $this->input('doctor_id') === (int) $this->input('patient_id')) {
                $validator->errors()->add(
                    'doctor_id',
                    'El identificador de paciente y de medico no pueden ser el mismo.',
                );
            }
        });
    }

    public function toData(): \App\Application\DTOs\BookAppointmentData
    {
        $validated = $this->validated();

        $status = isset($validated['status'])
            ? AppointmentStatus::from((string) $validated['status'])
            : AppointmentStatus::PENDING;

        return new \App\Application\DTOs\BookAppointmentData(
            patientId: (int) $validated['patient_id'],
            doctorId: (int) $validated['doctor_id'],
            timeRange: TimeRange::forAppointment(
                CarbonImmutable::parse((string) $validated['starts_at']),
                CarbonImmutable::parse((string) $validated['ends_at']),
            ),
            reason: $validated['reason'] ?? null,
            notes: $validated['notes'] ?? null,
            initialStatus: $status,
        );
    }
}
