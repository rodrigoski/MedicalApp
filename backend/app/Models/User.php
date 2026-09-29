<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operador del sistema (recepcion, medico, administrador).
 *
 * La API no autentica con este modelo en esta version: usa API keys internas.
 * El modelo existe porque el dominio real de una clinica tiene personal con
 * roles, y porque la dimension "Seguridad" de la auditoria debe poder
 * distinguir "quien es el usuario" de "que credencial uso".
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 */
final class User extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * @return array<string, string>
     */
    protected function hidden(): array
    {
        return [
            'password',
            'remember_token',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<AppointmentModel, User>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(\App\Infrastructure\Persistence\Models\AppointmentModel::class, 'created_by');
    }
}
