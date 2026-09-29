<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use App\Domain\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo Eloquent de la tabla `appointments`.
 *
 * Este modelo NO contiene reglas de negocio. La regla de no traslape se aplica
 * en App\Domain\Policies\AppointmentOverlapPolicy y, como red de seguridad a
 * nivel de motor, en la restriccion EXCLUDE creada en la migracion.
 */
final class AppointmentModel extends Model
{
    protected $table = 'appointments';

    /** @var list<string> */
    protected $fillable = [
        'patient_id',
        'doctor_id',
        'starts_at',
        'ends_at',
        'status',
        'reason',
        'notes',
        'confirmed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => AppointmentStatus::class,
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AppointmentModel, PatientModel>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientModel::class, 'patient_id');
    }

    /**
     * @return BelongsTo<AppointmentModel, DoctorModel>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorModel::class, 'doctor_id');
    }

    /**
     * Citas que ocupan agenda (las canceladas quedan excluidas).
     *
     * @param  Builder<AppointmentModel>  $query
     * @return Builder<AppointmentModel>
     */
    public function scopeOccupying(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AppointmentStatus::PENDING->value,
            AppointmentStatus::CONFIRMED->value,
        ]);
    }

    /**
     * Filtro de traslape: intervalo semiabierto, igual que TimeRange::overlaps().
     *
     *   starts_at < $to AND ends_at > $from
     *
     * @param  Builder<AppointmentModel>  $query
     * @return Builder<AppointmentModel>
     */
    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from);
    }
}
