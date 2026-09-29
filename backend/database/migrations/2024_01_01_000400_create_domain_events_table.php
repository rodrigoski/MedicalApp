<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Crea la tabla `domain_events`: la OUTBOX.
 *
 * Cuando se crea o modifica una cita, el caso de uso inserta aqui el evento
 * DENTRO DE LA MISMA TRANSACCION que la cita. Por eso no existen estos estados
 * inconsistentes:
 *   - "cita creada pero nunca notificada"  -> imposible de perder: el evento
 *     queda en la tabla y un worker lo entrega mas tarde.
 *   - "evento de algo que nunca ocurrio"    -> imposible: si la transaccion
 *     falla, se deshacen ambas escrituras.
 *
 * Esta tabla es la que permite que la API no dependa de la disponibilidad del
 * microservicio de notificaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('domain_events', function (Blueprint $table) use ($isPgsql): void {
            $table->id();

            // UUID v4 generado en la aplicacion: permite al consumidor detectar
            // entregas duplicadas (idempotencia) sin depender del id secuencial.
            $table->uuid('event_id')->unique();

            $table->string('event_type', 80);
            $table->string('aggregate_type', 40);
            $table->unsignedBigInteger('aggregate_id');

            if ($isPgsql) {
                $table->jsonb('payload');
            } else {
                $table->json('payload');
            }

            $table->timestampTz('occurred_at');
            $table->timestampTz('dispatched_at')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();

            // Indice parcial: solo se consultan los NO entregados, y son una
            // fraccion pequena de la tabla.
            $table->index('occurred_at', 'domain_events_occurred_index');
            $table->index(['dispatched_at', 'occurred_at'], 'domain_events_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
