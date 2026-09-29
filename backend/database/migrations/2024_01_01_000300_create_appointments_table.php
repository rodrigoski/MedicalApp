<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Crea la tabla `appointments`.
 *
 * ############################################################################
 * # RESTRICCION EXCLUDE: LA GARANTIA DE LA REGLA DE NO TRASLAPE            #
 * ############################################################################
 *
 * Traducimos la regla de negocio a una restriccion declarativa del motor. Se
 * definen DOS, una por cada parte de la cita:
 *
 *   EXCLUDE USING gist (doctor_id  WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)
 *     WHERE (status <> 'cancelled')
 *   EXCLUDE USING gist (patient_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)
 *     WHERE (status <> 'cancelled')
 *
 * Que significa, parte por parte:
 *   - EXCLUDE USING gist     -> "el motor rechazara cualquier combinacion que
 *                                viole esta condicion, en el instante del INSERT".
 *   - doctor_id/patient_id WITH = -> dos filas en conflicto si son del mismo
 *                                medico (o del mismo paciente).
 *   - tstzrange(...) WITH && -> dos filas en conflicto si sus intervalos de
 *                                tiempo se intersecan. El '[)' es la notacion de
 *                                intervalo semiabierto: 10:00-10:30 y 10:30-11:00
 *                                NO se intersecan (comparten el borde, no el
 *                                instante). Coincide exactamente con
 *                                TimeRange::overlaps() en PHP.
 *   - WHERE (status <> 'cancelled') -> las citas canceladas liberan el horario.
 *
 * POR QUE IMPORTA (dimension "Pruebas" y "Seguridad" de la auditoria):
 * la validacion en PHP es suficiente en el caso normal, pero dos peticiones
 * simultaneas pueden pasar ambas el `SELECT` de horarios libres antes de que
 * ninguna `INSERT` (fenomeno de "check-then-act"). Con esta restriccion, una
 * de las dos transacciones falla con SQLSTATE 23P01 y el usuario recibe 409, no
 * un 500. La base de datos es la ultima linea de defensa.
 *
 * REQUISITO: la extension `btree_gist` (para combinar `=` sobre bigint con
 * `&&` sobre rangos). Se instala en infra/postgres/init/01-init-databases.sh.
 * ############################################################################
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();

            // ON DELETE RESTRICT: no se puede borrar un paciente con historial
            // de citas. En un sistema clinico, el historico es intocable.
            $table->foreignId('patient_id')
                ->constrained('patients')
                ->restrictOnDelete();

            $table->foreignId('doctor_id')
                ->constrained('doctors')
                ->restrictOnDelete();

            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            $table->string('status', 20)->default('pending');
            $table->string('reason', 255)->nullable();
            $table->text('notes')->nullable();

            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();

            $table->timestampsTz();

            // Indice compuesto que soporta la consulta de traslape:
            // WHERE doctor_id = ? AND starts_at < ? AND ends_at > ?
            // (PostgreSQL puede usar el indice para el rango en starts_at y
            //  filtra ends_at en el heap; en tablas grandes se evaluaria un
            //  indice GiST dedicado).
            $table->index(['doctor_id', 'starts_at'], 'appointments_doctor_start_index');
            $table->index(['patient_id', 'starts_at'], 'appointments_patient_start_index');
            $table->index('status', 'appointments_status_index');
        });

        /*
        |----------------------------------------------------------------------
        | INVARIANTES DECLARATIVAS Y REGLA DE NO TRASLAPE A NIVEL DE MOTOR
        |----------------------------------------------------------------------
        | Todo lo que sigue es especifico de PostgreSQL: CHECK en ALTER TABLE y
        | EXCLUDE USING gist no existen en SQLite. Las pruebas rapidas corren en
        | SQLite y validan el COMPORTAMIENTO; la restriccion EXCLUDE se verifica
        | en la suite de PostgreSQL (ver phpunit.xml y docs/AUDITORIA.md,
        | dimension "Pruebas").
        */
        if (! $isPgsql) {
            return;
        }

        DB::statement("
            ALTER TABLE appointments ADD CONSTRAINT appointments_status_check
            CHECK (status IN ('pending', 'confirmed', 'cancelled'))
        ");

        // El fin debe ser posterior al inicio.
        DB::statement("
            ALTER TABLE appointments ADD CONSTRAINT appointments_time_check
            CHECK (ends_at > starts_at)
        ");

        // Duracion entre 10 minutos y 8 horas.
        // Los limites son un ESPEJO de TimeRange::MIN_DURATION_MINUTES y
        // MAX_DURATION_MINUTES: si se divergieran, la BD aceptaria citas que el
        // dominio rechaza, y el error llegaria como 500 en vez de 422.
        DB::statement("
            ALTER TABLE appointments ADD CONSTRAINT appointments_duration_check
            CHECK (
                (EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60) BETWEEN 10 AND 480
            )
        ");

        // Coherencia del estado con sus marcas de tiempo.
        DB::statement("
            ALTER TABLE appointments ADD CONSTRAINT appointments_state_check
            CHECK (
                (status = 'confirmed' AND confirmed_at IS NOT NULL)
                OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL)
                OR (status = 'pending' AND cancelled_at IS NULL)
            )
        ");

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        /*
        | REGLA DE NEGOCIO 1: un MEDICO no puede tener dos citas traslapadas.
        */
        DB::statement("
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_no_doctor_overlap
            EXCLUDE USING gist (
                doctor_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            )
            WHERE (status <> 'cancelled')
        ");

        /*
        | REGLA DE NEGOCIO 2: un PACIENTE tampoco puede estar en dos sitios a la
        | vez. Es el mismo invariante aplicado a la otra parte de la cita, y evita
        | depender por completo de la validacion en PHP.
        */
        DB::statement("
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_no_patient_overlap
            EXCLUDE USING gist (
                patient_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            )
            WHERE (status <> 'cancelled')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
