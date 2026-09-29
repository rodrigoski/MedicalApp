<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\DoctorController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST - ClinicApp
|--------------------------------------------------------------------------
| Este archivo se carga con el prefijo /api (ver bootstrap/app.php, apiPrefix).
| Versionado en la URL (/api/v1) para no romper clientes: una v2 podra convivir
| con esta sin romper a los consumidores actuales.
|
| REGLA DE SEGURIDAD DE ESTE ARCHIVO:
|   - Todo EXCEPTO /api/v1/health exige la cabecera X-Api-Key.
|   - /api/v1/health es PUBLICA a proposito: la necesitan el HEALTHCHECK de
|     Docker, el upstream de Nginx y cualquier monitor externo, y ninguno de
|     ellos puede adjuntar credenciales sin filtrarlas en los logs del proxy.
|     Por eso devuelve el MINIMO de informacion y nunca datos de negocio.
|   - El registro de peticiones y el identificador de trazabilidad NO se declaran
|     aqui sino en bootstrap/app.php, aplicado a todo el grupo `api` para que
|     tambien cubra /api/v1/health y los rechazos de autenticacion. Declararlo en
|     este grupo lo ejecutaria dos veces y duplicaria cada linea de log.
|
| LIMITACION DE TASA:
|   - Cada endpoint declara su propio limite (reads, writes, availability) y el
|     health check el suyo (health). Los limites se definen en
|     AppServiceProvider, no aqui: las rutas dicen QUE proteger, el proveedor
|     dice CUANTO.
*/

Route::prefix('v1')->group(static function (): void {

    /*
    |----------------------------------------------------------------------
    | SALUD (sin API key)
    |----------------------------------------------------------------------
    */
    Route::get('health', [HealthController::class, 'health'])
        ->middleware('throttle:health');

    /*
    |----------------------------------------------------------------------
    | API OPERATIVA (exige X-Api-Key)
    |----------------------------------------------------------------------
    */
        Route::middleware([
            'api.key',
            SecurityHeadersMiddleware::class,
        ])->group(static function (): void {

        /*
        |------------------------------------------------------------------
        | PACIENTES  (CRUD)
        |------------------------------------------------------------------
        */
        Route::prefix('patients')->group(static function (): void {
            Route::get('/', [PatientController::class, 'index'])
                ->middleware('throttle:reads');
            Route::get('{id}', [PatientController::class, 'show'])
                ->whereNumber('id')
                ->middleware('throttle:reads');

            Route::middleware('throttle:writes')->group(static function (): void {
                Route::post('/', [PatientController::class, 'store']);
                Route::patch('{id}', [PatientController::class, 'update'])
                    ->whereNumber('id');
                Route::delete('{id}', [PatientController::class, 'destroy'])
                    ->whereNumber('id');
            });
        });

        /*
        |------------------------------------------------------------------
        | MEDICOS  (CRUD)
        |------------------------------------------------------------------
        */
        Route::prefix('doctors')->group(static function (): void {
            Route::get('/', [DoctorController::class, 'index'])
                ->middleware('throttle:reads');
            Route::get('{id}', [DoctorController::class, 'show'])
                ->whereNumber('id')
                ->middleware('throttle:reads');

            Route::middleware('throttle:writes')->group(static function (): void {
                Route::post('/', [DoctorController::class, 'store']);
                Route::patch('{id}', [DoctorController::class, 'update'])
                    ->whereNumber('id');
                Route::delete('{id}', [DoctorController::class, 'destroy'])
                    ->whereNumber('id');
            });
        });

        /*
        |------------------------------------------------------------------
        | CITAS
        |------------------------------------------------------------------
        | Aqui se materializa la regla central: al crear o reprogramar una cita
        | se ejecuta AppointmentOverlapPolicy. El endpoint devuelve 409 con un
        | codigo de error explicito cuando el horario esta ocupado.
        */
        Route::prefix('appointments')->group(static function (): void {
            Route::get('/', [AppointmentController::class, 'index'])
                ->middleware('throttle:reads');
            Route::get('{id}', [AppointmentController::class, 'show'])
                ->whereNumber('id')
                ->middleware('throttle:reads');

            Route::middleware('throttle:writes')->group(static function (): void {
                Route::post('/', [AppointmentController::class, 'store']);
                Route::patch('{id}/status', [AppointmentController::class, 'updateStatus'])
                    ->whereNumber('id');
                Route::post('{id}/reschedule', [AppointmentController::class, 'reschedule'])
                    ->whereNumber('id');
                Route::delete('{id}', [AppointmentController::class, 'destroy'])
                    ->whereNumber('id');
            });
        });

        /*
        |------------------------------------------------------------------
        | DISPONIBILIDAD
        |------------------------------------------------------------------
        | Solo lectura, pero es el endpoint mas caro: calcula los slots libres
        | del medico. Por eso tiene su propio limite mas estricto.
        |
        | Se declara DESPUES de /patients, /doctors y /appointments para que
        | `doctors/{doctorId}/availability` nunca se interprete como
        | `doctors/{id}`.
        */
        Route::get('doctors/{doctorId}/availability', [AppointmentController::class, 'availability'])
            ->whereNumber('doctorId')
            ->middleware('throttle:availability');

        /*
        |------------------------------------------------------------------
        | ESTADISTICAS
        |------------------------------------------------------------------
        */
        Route::get('stats', [HealthController::class, 'stats'])
            ->middleware('throttle:reads');
    });
});
