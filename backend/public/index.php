<?php

declare(strict_types=1);

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Punto de entrada HTTP
|--------------------------------------------------------------------------
| Este es el UNICO archivo ejecutable por web. Todo lo demas (controladores,
| rutas, capas) se carga a traves del contenedor de dependencias de Laravel.
|
| Nginx no apunta directamente a app/: apunta aqui. Ver infra/nginx/nginx.conf.
*/

require __DIR__.'/../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
