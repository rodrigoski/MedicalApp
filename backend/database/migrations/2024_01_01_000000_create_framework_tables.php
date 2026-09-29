<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Tablas de soporte del framework (cache, colas y sesiones).
 *
 * Se crean en PostgreSQL y no en Redis para que el proyecto arranque con un
 * solo comando (requisito del enunciado) sin depender de un servicio extra.
 * En produccion, cambiar a Redis es cuestion de .env: ver la dimension
 * "Arquitectura" de docs/AUDITORIA.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key', 255)->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key', 255)->primary();
            $table->string('owner', 255);
            $table->integer('expiration');
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue', 120)->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id', 255)->primary();
            $table->string('name', 255);
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 255)->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id', 255)->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
