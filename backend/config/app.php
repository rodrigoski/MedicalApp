<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Aplicacion
    |--------------------------------------------------------------------------
    */

    'name' => env('APP_NAME', 'ClinicApp'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    | `version` se compara con la version del microservicio para detectar
    | despliegues incompatibles. No es decorativo: si la API dice 1.2.0 y el
    | microservicio 1.1.0, el contrato de eventos puede haber cambiado.
    */
    'version' => env('APP_VERSION', '1.0.0'),

    'url' => env('APP_URL', 'http://localhost:8000'),

    'asset_url' => env('ASSET_URL'),

    /*
    | TODO EL SISTEMA OPERA EN UTC.
    | Las comparaciones de intervalos de tiempo (la regla de no traslape) son
    | sensibles a zonas horarias y al horario de verano. Trabajar siempre en UTC
    | elimina esa categoria entera de errores; la conversion a hora local se
    | hace en la capa de presentacion, nunca al comparar.
    */
    'timezone' => 'UTC',

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => array_filter(
        explode(',', (string) env('APP_PREVIOUS_KEYS', '')),
    ),

    /*
    | API keys internas (cabecera X-Api-Key). Varias separadas por coma para
    | permitir rotacion sin caida. Se configuran en config/app.php para que
    | ApiKeys las lea una sola vez y las compare en tiempo constante.
    | NUNCA se registran en los logs (ver RequestLogger).
    */
    'internal_api_keys' => env('INTERNAL_API_KEY', ''),

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
