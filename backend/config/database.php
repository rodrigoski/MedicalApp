<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | El proyecto usa PostgreSQL porque la regla de no traslape se apoya en una
    | restriccion EXCLUDE sobre rangos (tstzrange) que SQLite no soporta.
    | Esa restriccion es la garantia a nivel de motor; sin ella, la proteccion
    | dependeria solo de PHP y fallaria ante peticiones concurrentes.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'clinic_app'),
            'username' => env('DB_USERNAME', 'clinic'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        | SQLite se mantiene para las pruebas unitarias rapidas y como opcion de
        | respaldo. No soporta la restriccion EXCLUDE, por lo que la migracion
        | de appointments detecta el driver y omite esa parte (ver la migracion).
        */
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | Migraciones
    |----------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |----------------------------------------------------------------------
    | Redis
    |----------------------------------------------------------------------
    | Definido para que cambiar de cache a Redis sea solo un cambio de .env.
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', 'clinicapp_database_'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
