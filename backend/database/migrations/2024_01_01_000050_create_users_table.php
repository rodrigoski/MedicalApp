<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Tabla de operadores del sistema (personal de la clinica).
 *
 * No se usa para autenticacion por token en esta version: la API se autentica
 * con API keys internas. Se deja el esquema porque es parte del modelo de datos
 * real de una clinica (recepcion, enfermeria, administracion) y porque las
 * auditorias de las 6 dimensiones preguntan por el control de acceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 254)->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('role', 30)->default('receptionist');
            $table->rememberToken();
            $table->timestampsTz();

            $table->index('role', 'users_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
