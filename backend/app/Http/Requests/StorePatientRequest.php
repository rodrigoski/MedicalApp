<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\ValueObjects\DocumentId;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validacion de entrada del alta de paciente.
 *
 * SEPARACION DE RESPONSABILIDADES (dimension "donde viven las validaciones"):
 *   - Aqui (capa HTTP) se valida el FORMATO y la presencia de campos:
 *     es una preocupacion de transporte, y solo esta capa conoce el formato de la
 *     peticion.
 *   - Las reglas de NEGOCIO (documento unico, no agendar en el pasado, no
 *     traslapar horarios) NO se validan aqui: viven en el Dominio
 *     (AppointmentOverlapPolicy) y en la capa de Aplicacion (PatientService).
 *
 * Consecuencia practica: llamar al caso de uso desde la consola, desde un job o
 * desde otro microservicio NO pierde ninguna validacion de negocio. Si la regla
 * estuviera en el FormRequest, solo existiria "por HTTP".
 */
final class StorePatientRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'document_id' => [
                'required',
                'string',
                'min:5',
                'max:20',
                'regex:/^[A-Za-z0-9\-\.\s]+$/',
                // Indice unico en la BD: se replica aqui para dar un 422 claro.
                Rule::unique('patients', 'document_id')->whereNull('deleted_at'),
            ],
            'email' => [
                'nullable',
                'email:rfc',
                'max:254',
                Rule::unique('patients', 'email')->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^\+?[0-9\s\-()]{7,25}$/'],
            'birth_date' => [
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:today',
                'after:1900-01-01',
            ],
            'gender' => ['nullable', 'string', Rule::in(['M', 'F', 'O', 'X'])],
            'address' => ['nullable', 'string', 'max:180'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:25'],
            'allergies' => ['sometimes', 'array', 'max:20'],
            'allergies.*' => ['string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'El nombre completo es obligatorio.',
            'full_name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'document_id.regex' => 'El documento solo admite letras, numeros, guiones y puntos.',
            'document_id.unique' => 'Ya existe un paciente registrado con ese documento.',
            'email.email' => 'El correo no tiene un formato valido.',
            'email.unique' => 'Ya existe un paciente registrado con ese correo.',
            'birth_date.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
        ];
    }

    /**
     * Normaliza antes de validar (trim y unificacion de formato).
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        if ($this->has('full_name')) {
            $normalised['full_name'] = trim((string) $this->input('full_name'));
        }

        if ($this->has('document_id')) {
            $normalised['document_id'] = DocumentId::normalise((string) $this->input('document_id'));
        }

        if ($this->has('email') && $this->input('email') !== null && $this->input('email') !== '') {
            $normalised['email'] = trim((string) $this->input('email'));
        }

        if ($this->has('phone') && $this->input('phone') !== null && $this->input('phone') !== '') {
            $normalised['phone'] = trim((string) $this->input('phone'));
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    /**
     * DTO listo para el caso de uso, ya con los value objects construidos.
     */
    public function toData(): \App\Application\DTOs\CreatePatientData
    {
        return \App\Application\DTOs\CreatePatientData::fromArray($this->validated());
    }
}
