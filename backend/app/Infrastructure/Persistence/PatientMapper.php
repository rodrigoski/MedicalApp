<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Patient;
use App\Domain\Enums\ResourceStatus;
use App\Domain\ValueObjects\DocumentId;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\PhoneNumber;
use App\Infrastructure\Persistence\Models\PatientModel;
use Carbon\CarbonImmutable;

/**
 * Traductor entre el modelo Eloquent de paciente y la entidad de dominio.
 */
final class PatientMapper
{
    public static function toEntity(PatientModel $model): Patient
    {
        return Patient::reconstitute(
            id: (int) $model->id,
            fullName: (string) $model->full_name,
            documentId: DocumentId::from((string) $model->document_id),
            email: Email::tryFrom($model->email),
            phone: PhoneNumber::tryFrom($model->phone),
            birthDate: $model->birth_date !== null
                ? CarbonImmutable::parse($model->birth_date)->startOfDay()
                : null,
            gender: $model->gender,
            address: $model->address,
            emergencyContactName: $model->emergency_contact_name,
            emergencyContactPhone: PhoneNumber::tryFrom($model->emergency_contact_phone),
            allergies: is_array($model->allergies) ? array_map('strval', $model->allergies) : [],
            status: $model->status instanceof ResourceStatus
                ? $model->status
                : ResourceStatus::from((string) ($model->status ?? 'active')),
            isDeleted: $model->deleted_at !== null,
            createdAt: CarbonImmutable::instance($model->created_at),
            updatedAt: CarbonImmutable::instance($model->updated_at),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function toAttributes(Patient $patient): array
    {
        return [
            'full_name' => $patient->fullName(),
            'document_id' => $patient->documentId()->value(),
            'email' => $patient->email()?->value(),
            'phone' => $patient->phone()?->value(),
            'birth_date' => $patient->birthDate()?->toDateString(),
            'gender' => $patient->gender(),
            'address' => $patient->address(),
            'emergency_contact_name' => $patient->emergencyContactName(),
            'emergency_contact_phone' => $patient->emergencyContactPhone()?->value(),
            'allergies' => $patient->allergies(),
            'status' => $patient->status()->value,
            'updated_at' => $patient->updatedAt(),
        ];
    }
}
