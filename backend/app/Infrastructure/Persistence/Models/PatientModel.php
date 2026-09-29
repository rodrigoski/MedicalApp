<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use App\Domain\Enums\ResourceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo Eloquent de la tabla `patients` (detalle de persistencia).
 */
final class PatientModel extends Model
{
    use SoftDeletes;

    protected $table = 'patients';

    /** @var list<string> */
    protected $fillable = [
        'full_name',
        'document_id',
        'email',
        'phone',
        'birth_date',
        'gender',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'status',
        'allergies',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ResourceStatus::class,
            'birth_date' => 'immutable_date',
            'allergies' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<AppointmentModel, PatientModel>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(AppointmentModel::class, 'patient_id');
    }

    /**
     * @param  Builder<PatientModel>  $query
     * @return Builder<PatientModel>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ResourceStatus::ACTIVE->value);
    }
}
