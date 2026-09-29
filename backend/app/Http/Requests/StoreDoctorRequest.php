<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\LicenseNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDoctorRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'min:5', 'max:120'],
            'license_number' => [
                'required',
                'string',
                'min:4',
                'max:20',
                'regex:/^[A-Za-z0-9\-\.\s]+$/',
                Rule::unique('doctors', 'license_number')->whereNull('deleted_at'),
            ],
            'specialty' => ['required', 'string', 'min:3', 'max:80'],
            'email' => ['nullable', 'email:rfc', 'max:254', Rule::unique('doctors', 'email')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^\+?[0-9\s\-()]{7,25}$/'],
            'working_day_start_hour' => ['nullable', 'integer', 'between:0,23'],
            'working_day_end_hour' => ['nullable', 'integer', 'between:1,23', 'gt:working_day_start_hour'],
            'slot_duration_minutes' => ['nullable', 'integer', 'between:5,240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'license_number.unique' => 'Ya existe un medico con esa matricula profesional.',
            'email.unique' => 'Ese correo ya pertenece a otro medico.',
            'working_day_end_hour.gt' => 'La jornada debe terminar despues de empezar.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        if ($this->has('full_name')) {
            $normalised['full_name'] = trim((string) $this->input('full_name'));
        }

        if ($this->has('license_number')) {
            $normalised['license_number'] = strtoupper(
                (string) preg_replace('/[\s\-.]+/', '', (string) $this->input('license_number')),
            );
        }

        if ($this->has('specialty')) {
            $normalised['specialty'] = trim((string) $this->input('specialty'));
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    public function toData(): \App\Application\DTOs\CreateDoctorData
    {
        return \App\Application\DTOs\CreateDoctorData::fromArray($this->validated());
    }
}
