<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\ResourceStatus;
use App\Domain\ValueObjects\Email;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Operadores de la clinica (datos de demostracion).
 *
 * NOTA: los medicos, pacientes y citas NO se siembran aqui. Se cargan con
 * `php artisan clinic:seed-demo`, que usa los casos de uso reales para que el
 * conjunto de datos respete la regla de no traslape.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $operators = [
            ['Ana Sofia Rios', 'admin@clinicapp.local', 'admin'],
            ['Carlos Andres Mejia', 'recepcion@clinicapp.local', 'receptionist'],
            ['Lucia Fernandez', 'medico@clinicapp.local', 'doctor'],
        ];

        foreach ($operators as [$name, $email, $role]) {
            DB::table('users')->updateOrInsert(
                ['email' => $email],
                [
                    'name' => $name,
                    // Hash bcrypt: nunca sealmacena una contrasena en claro.
                    'password' => Hash::make('clinicapp2024'),
                    'role' => $role,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ],
            );
        }

        $this->command?->info(sprintf('  %d operadores registrados.', count($operators)));
    }
}
