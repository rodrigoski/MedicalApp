<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Contracts\ClockInterface;
use App\Domain\Entities\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representacion JSON de un paciente.
 *
 * NOTA ARQUITECTONICA (por que no se expone `Patient::toArray()` directamente):
 *
 *  1. La entidad es un modelo de DOMINIO: sus claves son en camelCase y su
 *     objetivo es la regla de negocio, no el contrato publico. Reutilizarla
 *     como respuesta HTTP acopla la API a la forma interna del dominio: el dia
 *     que se renombre un metodo, se rompe el contrato de los clientes.
 *  2. El resource es la CAPA DE TRADUCCION (Domain -> HTTP). Si el dia la API
 *     se expone en GraphQL o por gRPC, cada uno tendra su propia traduccion y el
 *     dominio no cambia.
 *  3. `toArray()` de la entidad devuelve 'age' calculado con el reloj real
 *     (`ageInYears()` sin instante de referencia), lo que hace la respuesta no
 *     reproducible en pruebas. Aqui se calcula con el reloj inyectado.
 *
 * @property-read Patient $resource
 */
final class PatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $patient = $this->resource;

        return [
            'id' => $patient->id(),
            'full_name' => $patient->fullName(),
            'document_id' => $patient->documentId()->value(),
            'email' => $patient->email()?->value(),
            'phone' => $patient->phone()?->value(),
            'birth_date' => $patient->birthDate()?->toDateString(),
            'age' => $this->age($patient),
            'gender' => $patient->gender(),
            'address' => $patient->address(),
            'emergency_contact' => [
                'name' => $patient->emergencyContactName(),
                'phone' => $patient->emergencyContactPhone()?->value(),
            ],
            'allergies' => $patient->allergies(),
            'has_allergies' => $patient->hasAllergies(),
            'status' => $patient->status()->value,
            'status_label' => $patient->status()->label(),
            'created_at' => $patient->createdAt()->toIso8601String(),
            'updated_at' => $patient->updatedAt()->toIso8601String(),
        ];
    }

    /**
     * Edad en anios usando el reloj de la aplicacion (congelado en pruebas).
     */
    private function age(Patient $patient): ?int
    {
        if ($patient->birthDate() === null) {
            return null;
        }

        // Se resuelve el reloj desde el contenedor, no desde `now()` estatico:
        // asi la edad que ve un cliente es la misma que la que ven las pruebas.
        // `instance()` es la misma coercion que usan los casos de uso, porque el
        // contrato expone DateTimeImmutable y el dominio trabaja con Carbon.
        return $patient->ageInYears(CarbonImmutable::instance(app(ClockInterface::class)->now()));
    }
}
