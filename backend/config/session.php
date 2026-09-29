<?php

declare(strict_types=1);

return [

    'default' => env('SESSION_DRIVER', 'array'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => false,

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => env('SESSION_TABLE', 'sessions'),

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env(
        'SESSION_COOKIE',
        'clinicapp_session',
    ),

    'path' => env('SESSION_PATH', '/'),

    'domain' => env('SESSION_DOMAIN'),

    'secure' => env('SESSION_SECURE_COOKIE'),

    'http_only' => true,

    'same_site' => 'lax',

    'partitioned' => false,

];
