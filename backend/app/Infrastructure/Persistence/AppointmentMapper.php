<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Appointment;
use App\Domain\Enums\AppointmentStatus;
use App\Domain\ValueObjects\TimeRange;
use App\Infrastructure\Persistence\Models\AppointmentModel;
use Carbon\CarbonImmutable;

final class AppointmentMapper
{
    public static function toEntity(AppointmentModel $model): Appointment
    {
        return Appointment::reconstitute(
            id: (int) $model->id,
            patientId: (int) $model->patient_id,
            doctorId: (int) $model->doctor_id,
            timeRange: TimeRange::from(
                CarbonImmutable::instance($model->starts_at),
                CarbonImmutable::instance($model->ends_at),
            ),
            status: $model->status instanceof AppointmentStatus
                ? $model->status
                : AppointmentStatus::from((string) $model->status),
            reason: $model->reason,
            notes: $model->notes,
            confirmedAt: $model->confirmed_at !== null
                ? CarbonImmutable::instance($model->confirmed_at)
                : null,
            cancelledAt: $model->cancelled_at !== null
                ? CarbonImmutable::instance($model->cancelled_at)
                : null,
            cancellationReason: $model->cancellation_reason,
            createdAt: CarbonImmutable::instance($model->created_at),
            updatedAt: CarbonImmutable::instance($model->updated_at),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function toAttributes(Appointment $appointment): array
    {
        return [
            'patient_id' => $appointment->patientId(),
            'doctor_id' => $appointment->doctorId(),
            'starts_at' => $appointment->timeRange()->startAt(),
            'ends_at' => $appointment->timeRange()->endAt(),
            'status' => $appointment->status()->value,
            'reason' => $appointment->reason(),
            'notes' => $appointment->notes(),
            'confirmed_at' => $appointment->confirmedAt(),
            'cancelled_at' => $appointment->cancelledAt(),
            'cancellation_reason' => $appointment->cancellationReason(),
            'updated_at' => $appointment->updatedAt(),
        ];
    }
}
