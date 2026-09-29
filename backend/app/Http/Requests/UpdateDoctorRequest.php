<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDoctorRequest extends FormRequest
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
        $doctorId = (int) $this->route('id');

        return [
            'full_name' => ['sometimes', 'required', 'string', 'min:5', 'max:120'],
            'specialty' => ['sometimes', 'required', 'string', 'min:3', 'max:80'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254', Rule::unique('doctors', 'email')->ignore($doctorId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:25'],
            'working_day_start_hour' => ['sometimes', 'integer', 'between:0,23'],
            'working_day_end_hour' => ['sometimes', 'integer', 'between:1,23'],
            'slot_duration_minutes' => ['sometimes', 'integer', 'between:5,240'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ];
    }

    public function toData(): \App\Application\DTOs\UpdateDoctorData
    {
        return \App\Application\DTOs\UpdateDoctorData::fromArray($this->validated());
    }
}
