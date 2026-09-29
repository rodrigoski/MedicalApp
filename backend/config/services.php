<?php

declare(strict_types=1);

return [

    /*
    |----------------------------------------------------------------------
    | Microservicio de notificaciones (FastAPI)
    |----------------------------------------------------------------------
    |
    | La URL es un nombre de servicio de Docker (`http://notification-service:8000`),
    | NO un localhost: dentro de la red de contenedores cada servicio se
    | resuelve por su nombre. Esa es una decision de despliegue, no de codigo:
    | la aplicacion no sabe si corre en Docker, en Kubernetes o en un servidor.
    |
    */

    'notification_service' => [
        'url' => env('NOTIFICATION_SERVICE_URL', 'http://notification-service:8000'),
        'api_key' => env('NOTIFICATION_SERVICE_API_KEY', ''),
        'timeout' => (int) env('NOTIFICATION_SERVICE_TIMEOUT', 4),
        'retries' => (int) env('NOTIFICATION_SERVICE_RETRIES', 2),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];
