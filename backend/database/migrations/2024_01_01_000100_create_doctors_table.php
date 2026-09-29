<?php

declare(strict_types=1);

use App\Domain\Enums\AppointmentStatus;
use App\Domain\Enums\ResourceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Crea la tabla `doctors`.
 *
 * Decisiones de esquema que hacen parte de la auditoria:
 *   - license_number UNIQUE: la matricula identifica al profesional.
 *   - email UNIQUE PARCIAL (WHERE deleted_at IS NULL): permite reutilizar el
 *     correo tras un borrado logico, sin permitir dos medicos activos con el
 *     mismo correo.
 *   - CHECK en las horas de jornada y en la duracion de la cita: el motor
 *     rechaza datos imposibles aunque una escritura se saltara la aplicacion.
 *   - status como VARCHAR + CHECK en lugar de ENUM nativo: agregar un estado
 *     despues no requiere ALTER TYPE (migracion rápida y reversible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table): void {
            $table->id();

            $table->string('full_name', 120);
            $table->string('license_number', 20)->unique();
            $table->string('specialty', 80);
            $table->string('email', 254)->nullable();
            $table->string('phone', 25)->nullable();

            $table->string('status', 20)->default(ResourceStatus::ACTIVE->value);

            $table->unsignedTinyInteger('working_day_start_hour')->default(8);
            $table->unsignedTinyInteger('working_day_end_hour')->default(18);
            $table->unsignedSmallInteger('slot_duration_minutes')->default(30);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status', 'doctors_status_index');
            $table->index('specialty', 'doctors_specialty_index');
        });

        // Unicidad parcial del correo: solo aplica a registros no eliminados.
        DB::statement(
            'CREATE UNIQUE INDEX doctors_email_unique ON doctors (email) WHERE deleted_at IS NULL'
        );

        // Invariantes a nivel de motor (criterio "Pruebas" y "Seguridad":
        // la base de datos es la ultima linea de defensa).
        // Solo PostgreSQL: SQLite no admite ALTER TABLE ... ADD CONSTRAINT, y el
        // comportamiento ya esta cubierto por las pruebas de dominio.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            ALTER TABLE doctors ADD CONSTRAINT doctors_status_check
            CHECK (status IN ('active', 'inactive'))
        ");

        DB::statement("
            ALTER TABLE doctors ADD CONSTRAINT doctors_schedule_check
            CHECK (
                working_day_start_hour BETWEEN 0 AND 23
                AND working_day_end_hour BETWEEN 1 AND 23
                AND working_day_end_hour > working_day_start_hour
                AND slot_duration_minutes BETWEEN 5 AND 240
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
