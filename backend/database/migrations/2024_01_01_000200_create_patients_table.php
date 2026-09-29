<?php

declare(strict_types=1);

use App\Domain\Enums\ResourceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Crea la tabla `patients`.
 *
 * - document_id UNIQUE PARCIAL: el documento identifica al paciente; se libera
 *   al hacer borrado logico para permitir un alta posterior.
 * - birth_date con CHECK de rango razonable (no puede estar en el futuro ni
 *   ser anterior a 1900) para que un dato corrupto no llegue a produccion.
 * - allergies como JSONB: lista de strings corta y no consultable con
 *   frecuencia. Se normalizaria en tabla aparte si hubiera que reportar por
 *   alergeno.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('patients', function (Blueprint $table) use ($isPgsql): void {
            $table->id();

            $table->string('full_name', 120);
            $table->string('document_id', 20);
            $table->string('email', 254)->nullable();
            $table->string('phone', 25)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 1)->nullable();
            $table->string('address', 180)->nullable();

            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 25)->nullable();

            $table->string('status', 20)->default(ResourceStatus::ACTIVE->value);

            // `jsonb` es nativo de PostgreSQL. En SQLite (pruebas rapidas) se
            // degrada a `json` -> TEXT, que el cast 'array' de Eloquent maneja
            // igual. La semantica de consulta no cambia para el caso de uso.
            if ($isPgsql) {
                $table->jsonb('allergies')->nullable();
            } else {
                $table->json('allergies')->nullable();
            }

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status', 'patients_status_index');
        });

        // Indices unicos PARCIALES: el documento/correo se libera al hacer borrado
        // logico. SQLite soporta indices parciales con la misma sintaxis.
        DB::statement(
            'CREATE UNIQUE INDEX patients_document_id_unique ON patients (document_id) WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX patients_email_unique ON patients (email) WHERE deleted_at IS NULL'
        );

        // Invariantes declarativas: PostgreSQL en produccion. SQLite no admite
        // ALTER TABLE ... ADD CONSTRAINT, y las pruebas de dominio ya cubren
        // estas reglas; por eso se omiten en ese motor.
        if (! $isPgsql) {
            return;
        }

        DB::statement("
            ALTER TABLE patients ADD CONSTRAINT patients_status_check
            CHECK (status IN ('active', 'inactive'))
        ");

        DB::statement("
            ALTER TABLE patients ADD CONSTRAINT patients_gender_check
            CHECK (gender IS NULL OR gender IN ('M', 'F', 'O', 'X'))
        ");

        DB::statement("
            ALTER TABLE patients ADD CONSTRAINT patients_birth_date_check
            CHECK (birth_date IS NULL OR (birth_date <= CURRENT_DATE AND birth_date >= DATE '1900-01-01'))
        ");

        DB::statement("
            ALTER TABLE patients ADD CONSTRAINT patients_name_check
            CHECK (char_length(trim(full_name)) >= 3)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
