<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use App\Domain\Enums\ResourceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo Eloquent de la tabla `doctors`.
 *
 * NOTA ARQUITECTONICA: esta clase NO es la entidad de negocio. La entidad
 * `App\Domain\Entities\Doctor` no sabe que existe. Este modelo es un detalle de
 * persistencia y no debe usarse fuera de App\Infrastructure\Persistence.
 *
 * Los `casts` a enum y a fechas son la frontera de traduccion: aqui se
 * convierten los tipos de PostgreSQL en objetos de dominio.
 */
final class DoctorModel extends Model
{
    use SoftDeletes;

    protected $table = 'doctors';

    /** @var list<string> */
    protected $fillable = [
        'full_name',
        'license_number',
        'specialty',
        'email',
        'phone',
        'status',
        'working_day_start_hour',
        'working_day_end_hour',
        'slot_duration_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ResourceStatus::class,
            'working_day_start_hour' => 'integer',
            'working_day_end_hour' => 'integer',
            'slot_duration_minutes' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<DoctorModel, AppointmentModel>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(AppointmentModel::class, 'doctor_id');
    }

    /**
     * Filtra medicos que pueden recibir citas.
     *
     * @param  Builder<DoctorModel>  $query
     * @return Builder<DoctorModel>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ResourceStatus::ACTIVE->value);
    }

    /**
     * Filtra medicos por especialidad.
     *
     * Se compara sin distincion de mayusculas. En PostgreSQL se usa ILIKE
     * (puede aprovechar indice y no exige envolver la columna en LOWER()); en
     * SQLite, que es el motor de las pruebas rapidas, ILIKE no existe y se
     * emula con LIKE.
     *
     * @param  Builder<DoctorModel>  $query
     * @return Builder<DoctorModel>
     */
    public function scopeSpecialty(Builder $query, string $specialty): Builder
    {
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $query->where('specialty', $operator, $specialty);
    }
}
