<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Doctor;
use App\Domain\Enums\ResourceStatus;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\LicenseNumber;
use App\Domain\ValueObjects\PhoneNumber;
use App\Infrastructure\Persistence\Models\DoctorModel;
use Carbon\CarbonImmutable;

/**
 * Traductor entre el modelo Eloquent y la entidad de dominio.
 *
 * Es el UNICO lugar donde se convierten filas en objetos de negocio. Si manana
 * el almacenamiento cambia (por ejemplo a SQL Server o a una API REST), este
 * mapper es lo unico que se reescribe junto con el repositorio: ni las
 * entidades ni los servicios cambian.
 */
final class DoctorMapper
{
    public static function toEntity(DoctorModel $model): Doctor
    {
        return Doctor::reconstitute(
            id: (int) $model->id,
            fullName: (string) $model->full_name,
            licenseNumber: LicenseNumber::from((string) $model->license_number),
            specialty: (string) $model->specialty,
            email: Email::tryFrom($model->email),
            phone: PhoneNumber::tryFrom($model->phone),
            status: $model->status instanceof ResourceStatus
                ? $model->status
                : ResourceStatus::from((string) ($model->status ?? 'active')),
            isDeleted: $model->deleted_at !== null,
            workingDayStartHour: (int) ($model->working_day_start_hour ?? 8),
            workingDayEndHour: (int) ($model->working_day_end_hour ?? 18),
            slotDurationMinutes: (int) ($model->slot_duration_minutes ?? 30),
            createdAt: CarbonImmutable::instance($model->created_at),
            updatedAt: CarbonImmutable::instance($model->updated_at),
        );
    }

    /**
     * Traduce una entidad a atributos persistibles.
     *
     * @return array<string, mixed>
     */
    public static function toAttributes(Doctor $doctor): array
    {
        return [
            'full_name' => $doctor->fullName(),
            'license_number' => $doctor->licenseNumber()->value(),
            'specialty' => $doctor->specialty(),
            'email' => $doctor->email()?->value(),
            'phone' => $doctor->phone()?->value(),
            'status' => $doctor->status()->value,
            'working_day_start_hour' => $doctor->workingDayStartHour(),
            'working_day_end_hour' => $doctor->workingDayEndHour(),
            'slot_duration_minutes' => $doctor->slotDurationMinutes(),
            'updated_at' => $doctor->updatedAt(),
        ];
    }
}
