<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Enums\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAppointmentStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(AppointmentStatus::values())],
            // REGLA: la cancelacion exige motivo. Se valida aqui porque es una
            // condicion de forma del comando (el campo debe venir informado),
            // no una regla que requiera consultar otros datos.
            'reason' => [
                Rule::requiredIf(fn (): bool => $this->input('status') === AppointmentStatus::CANCELLED->value),
                'nullable',
                'string',
                'min:5',
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Debe indicar el nuevo estado de la cita.',
            'reason.required' => 'Debe indicar el motivo de la cancelacion.',
            'reason.min' => 'El motivo debe tener al menos 5 caracteres.',
        ];
    }

    public function toData(): \App\Application\DTOs\ChangeStatusData
    {
        return \App\Application\DTOs\ChangeStatusData::fromArray($this->validated());
    }
}
