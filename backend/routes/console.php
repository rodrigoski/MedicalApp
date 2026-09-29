<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
| Archivo de rutas de consola: aqui viven los comandos programados (cron).
| NO se definen rutas HTTP aqui: eso es trabajo de routes/web.php y
| routes/api.php. (Mezclar ambos hacia que las rutas de la API se registraran
| dos veces, una por el cargador de rutas y otra por el de consola.)
|
| Las tareas se ejecutan con:  php artisan schedule:work   (desarrollo)
|                              php artisan schedule:run    (produccion, cron)
*/

// 1. Entrega de la outbox al microservicio de notificaciones.
//    Sin esto, un evento fallido solo se reintentaria al crear otra cita.
Schedule::command('events:dispatch --limit=50')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// 2. Poda de la outbox: los eventos YA entregados y antiguos se eliminan para
//    que la tabla no crezca de forma indefinida (dimension Rendimiento).
Schedule::command('events:prune --days='.config('clinic.events.prune_after_days', 90))
    ->dailyAt('03:17')
    ->withoutOverlapping()
    ->onOneServer();

// 3. Limpieza de lotes de cola fallidos.
Schedule::command('queue:prune-failed --hours=72')->hourly();
