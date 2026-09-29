<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enums\ResourceStatus;
use App\Domain\ValueObjects\DocumentId;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * Agregado Paciente.
 *
 * El constructor es PRIVADO: la unica forma de crear un paciente es calling
 * Patient::register(), que aplica los valores por defecto y normaliza. Asi se
 * garantiza que ninguna instancia exista con datos invalidos, sin importar
 * desde donde venga (controlador, seeder, consola o cola).
 */
final class Patient
{
    /**
     * Limites de la lista de alergias (alineados con la validacion HTTP para que
     * el dominio nunca acepte mas de lo que permite la API).
     */
    public const MAX_ALLERGIES = 20;
    public const MAX_ALLERGY_LENGTH = 80;

    private function __construct(
        private ?int $id,
        private string $fullName,
        private readonly DocumentId $documentId,
        private ?Email $email,
        private ?PhoneNumber $phone,
        private readonly ?CarbonImmutable $birthDate,
        private ?string $gender,
        private ?string $address,
        private ?string $emergencyContactName,
        private ?PhoneNumber $emergencyContactPhone,
        private array $allergies,
        private ResourceStatus $status,
        private bool $isDeleted,
        private CarbonImmutable $createdAt,
        private CarbonImmutable $updatedAt,
    ) {
    }

    public static function register(
        string $fullName,
        DocumentId $documentId,
        ?Email $email = null,
        ?PhoneNumber $phone = null,
        ?CarbonImmutable $birthDate = null,
        ?string $gender = null,
        ?string $address = null,
        ?string $emergencyContactName = null,
        ?PhoneNumber $emergencyContactPhone = null,
        ?array $allergies = null,
        ?CarbonImmutable $now = null,
    ): self {
        $timestamp = $now ?? CarbonImmutable::now('UTC');

        return new self(
            id: null,
            fullName: self::sanitiseName($fullName),
            documentId: $documentId,
            email: $email,
            phone: $phone,
            birthDate: $birthDate,
            gender: $gender !== null ? strtoupper(trim($gender)) : null,
            address: $address !== null ? trim($address) : null,
            emergencyContactName: $emergencyContactName !== null ? trim($emergencyContactName) : null,
            emergencyContactPhone: $emergencyContactPhone,
            allergies: self::normaliseAllergies($allergies),
            status: ResourceStatus::ACTIVE,
            isDeleted: false,
            createdAt: $timestamp,
            updatedAt: $timestamp,
        );
    }

    /**
     * Reconstruccion desde persistencia (usado por el repositorio).
     *
     * @internal El repositorio es la unica capa autorizada a "reabrir" la entidad.
     */
    public static function reconstitute(
        int $id,
        string $fullName,
        DocumentId $documentId,
        ?Email $email,
        ?PhoneNumber $phone,
        ?CarbonImmutable $birthDate,
        ?string $gender,
        ?string $address,
        ?string $emergencyContactName,
        ?PhoneNumber $emergencyContactPhone,
        array $allergies,
        ResourceStatus $status,
        bool $isDeleted,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $fullName,
            $documentId,
            $email,
            $phone,
            $birthDate,
            $gender,
            $address,
            $emergencyContactName,
            $emergencyContactPhone,
            self::normaliseAllergies($allergies),
            $status,
            $isDeleted,
            $createdAt,
            $updatedAt,
        );
    }

    // --- Comportamiento ------------------------------------------------------

    public function rename(string $fullName, ?CarbonImmutable $now = null): void
    {
        $this->fullName = self::sanitiseName($fullName);
        $this->touch($now);
    }

    public function changeEmail(?Email $email, ?CarbonImmutable $now = null): void
    {
        $this->email = $email;
        $this->touch($now);
    }

    public function changePhone(?PhoneNumber $phone, ?CarbonImmutable $now = null): void
    {
        $this->phone = $phone;
        $this->touch($now);
    }

    public function changeContactData(
        ?PhoneNumber $phone,
        ?string $address,
        ?CarbonImmutable $now = null,
    ): void {
        $this->phone = $phone;
        $this->address = $address !== null ? trim($address) : null;
        $this->touch($now);
    }

    public function changeEmergencyContact(
        ?string $name,
        ?PhoneNumber $phone,
        ?CarbonImmutable $now = null,
    ): void {
        $this->emergencyContactName = $name !== null ? trim($name) : null;
        $this->emergencyContactPhone = $phone;
        $this->touch($now);
    }

    public function changeBirthDate(?CarbonImmutable $birthDate): void
    {
        $this->birthDate = $birthDate;
        $this->touch();
    }

    public function changeGender(?string $gender): void
    {
        $this->gender = $gender !== null ? strtoupper(trim($gender)) : null;
        $this->touch();
    }

    /**
     * Alergias y MASTER DATA CLINICA.
     *
     * La normalizacion ocurre aqui, y no en el controlador ni en el repositorio,
     * porque es una regla del DOMINIO: "Penicilina" y " penicilina " son la misma
     * alergia, y una lista con duplicados o cadenas vacias es un dato sucio que
     * terminaria confundiendo al medico en una urgencia.
     *
     * @param  iterable<mixed>|null  $allergies
     */
    public function changeAllergies(?iterable $allergies): void
    {
        $this->allergies = self::normaliseAllergies($allergies);
        $this->touch();
    }

    public function deactivate(?CarbonImmutable $now = null): void
    {
        $this->status = ResourceStatus::INACTIVE;
        $this->touch($now);
    }

    public function activate(?CarbonImmutable $now = null): void
    {
        $this->status = ResourceStatus::ACTIVE;
        $this->touch($now);
    }

    public function isActive(): bool
    {
        return $this->status->isActive() && ! $this->isDeleted;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }

    public function ageInYears(?CarbonImmutable $reference = null): ?int
    {
        if ($this->birthDate === null) {
            return null;
        }

        // El instante de referencia es un PARAMETRO, no un detalle interno: sin
        // el, la edad dependeria del reloj del sistema y la respuesta no seria
        // reproducible en pruebas. El appelante decide "edad a la fecha de hoy".
        $reference ??= CarbonImmutable::now('UTC');

        return (int) abs($reference->diffInYears($this->birthDate));
    }

    // --- Accesores -----------------------------------------------------------

    public function id(): ?int
    {
        return $this->id;
    }

    public function fullName(): string
    {
        return $this->fullName;
    }

    public function documentId(): DocumentId
    {
        return $this->documentId;
    }

    public function email(): ?Email
    {
        return $this->email;
    }

    public function phone(): ?PhoneNumber
    {
        return $this->phone;
    }

    public function birthDate(): ?CarbonImmutable
    {
        return $this->birthDate;
    }

    public function gender(): ?string
    {
        return $this->gender;
    }

    public function address(): ?string
    {
        return $this->address;
    }

    public function emergencyContactName(): ?string
    {
        return $this->emergencyContactName;
    }

    public function emergencyContactPhone(): ?PhoneNumber
    {
        return $this->emergencyContactPhone;
    }

    /**
     * Alergias registradas, ya normalizadas y sin duplicados.
     *
     * @return list<string>
     */
    public function allergies(): array
    {
        return $this->allergies;
    }

    /**
     * Indica si el paciente tiene alguna alergia registrada. Es la consulta que
     * haria la pantalla de atencion antes de recetar.
     */
    public function hasAllergies(): bool
    {
        return $this->allergies !== [];
    }

    public function status(): ResourceStatus
    {
        return $this->status;
    }

    public function createdAt(): CarbonImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): CarbonImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->fullName,
            'document_id' => $this->documentId->value(),
            'email' => $this->email?->value(),
            'phone' => $this->phone?->value(),
            'birth_date' => $this->birthDate?->toDateString(),
            'gender' => $this->gender,
            'address' => $this->address,
            'emergency_contact_name' => $this->emergencyContactName,
            'emergency_contact_phone' => $this->emergencyContactPhone?->value(),
            'allergies' => $this->allergies,
            'status' => $this->status->value,
            'age' => $this->ageInYears(),
        ];
    }

    /**
     * Asigna el identificador tras el INSERT (unit of work).
     *
     * @internal
     */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('La entidad ya tiene identificador asignado.');
        }

        // El id y el estado de borrado son las unicas propiedades mutables fuera
        // del comportamiento: la identidad se fija al entrar en la base de datos
        // y el borrado logico lo aplica la capa de aplicacion.
        $this->id = $id;
    }

    /**
     * Marca la entidad como eliminada logicamente para la vista de lectura.
     *
     * @internal
     */
    public function markAsDeleted(): void
    {
        $this->isDeleted = true;
        $this->status = ResourceStatus::INACTIVE;
    }

    private function touch(?CarbonImmutable $now = null): void
    {
        $this->updatedAt = $now ?? CarbonImmutable::now('UTC');
    }

    private static function sanitiseName(string $name): string
    {
        $normalised = trim((string) preg_replace('/\s+/u', ' ', $name));

        return mb_convert_case($normalised, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Normaliza la lista de alergias: recorta, unifica mayusculas/minusculas,
     * elimina vacios y duplicados, y acota el tamano.
     *
     * @param  iterable<mixed>|null  $allergies
     * @return list<string>
     */
    private static function normaliseAllergies(?iterable $allergies): array
    {
        if ($allergies === null) {
            return [];
        }

        /** @var list<string> $normalised */
        $normalised = [];
        $seen = [];

        foreach ($allergies as $allergy) {
            $value = trim(mb_substr(trim((string) $allergy), 0, self::MAX_ALLERGY_LENGTH));

            if ($value === '') {
                continue;
            }

            $key = mb_strtolower($value);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalised[] = $value;

            if (count($normalised) >= self::MAX_ALLERGIES) {
                break;
            }
        }

        return $normalised;
    }
}
