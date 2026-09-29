<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePatientRequest extends FormRequest
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
        $patientId = (int) $this->route('id');

        return [
            'full_name' => ['sometimes', 'required', 'string', 'min:3', 'max:120'],
            'email' => [
                'sometimes',
                'nullable',
                'email:rfc',
                'max:254',
                // ignore($id) evita que el propio paciente choque consigo mismo.
                Rule::unique('patients', 'email')->ignore($patientId)->whereNull('deleted_at'),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:25', 'regex:/^\+?[0-9\s\-()]{7,25}$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:180'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['sometimes', 'nullable', Rule::in(['M', 'F', 'O', 'X'])],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:25'],
            // Una lista VACIA significa "borra todas las alergias"; si la clave no
            // viene, no se toca el campo. Por eso la regla es `sometimes`: si
            // fuese `required`, un PATCH parcial del nombre fallaria.
            'allergies' => ['sometimes', 'nullable', 'array', 'max:20'],
            'allergies.*' => ['string', 'max:80'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ese correo ya pertenece a otro paciente.',
            'status.in' => 'El estado debe ser "active" o "inactive".',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        if ($this->has('full_name')) {
            $normalised['full_name'] = trim((string) $this->input('full_name'));
        }

        if ($this->has('email')) {
            $normalised['email'] = $this->input('email') === null
                ? null
                : trim((string) $this->input('email'));
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    public function toData(): \App\Application\DTOs\UpdatePatientData
    {
        return \App\Application\DTOs\UpdatePatientData::fromArray($this->validated());
    }
}
