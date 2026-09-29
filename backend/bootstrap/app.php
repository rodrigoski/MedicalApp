<?php

declare(strict_types=1);

use App\Http\Exceptions\ExceptionMapper;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequestLogger;
use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Middleware propio de la API, registrado por nombre corto.
        $middleware->alias([
            'api.key' => AuthenticateApiKey::class,
            'security.headers' => SecurityHeadersMiddleware::class,
            'request.log' => RequestLogger::class,
        ]);

        // Forzar respuestas JSON: la API nunca debe devolver HTML (ni una
        // pagina de error de Laravel) aunque el cliente no pida JSON.
        //
        // RequestLogger va PRIMERO a proposito: al preceder al guardián de API
        // key, también deja traza de los rechazos de autenticacion, que son
        // precisamente los mas dificiles de diagnosticar con dos peticiones
        // simultaneas. Si corriera despues, un 401 no tendria identificador.
        $middleware->api(prepend: [
            \App\Http\Middleware\RequestLogger::class,
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        // Las dos rutas web (`/` y `/health-laravel`) tambien devuelven JSON y
        // tambien deben llevar identificador: son las que consulta un revisor
        // cuando algo va mal, y son justo las que se consultan sin credencial.
        $middleware->web(prepend: [
            \App\Http\Middleware\RequestLogger::class,
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        |------------------------------------------------------------------
        | Traduccion centralizada de excepciones -> respuestas JSON
        |------------------------------------------------------------------
        | Ver App\Http\Exceptions\ExceptionMapper para la justificacion de por
        | que el mapeo vive en un unico archivo y no en cada controlador.
        */
        $exceptions->render(function (Throwable $e, Request $request) {
            return ExceptionMapper::render($e, $request);
        });

        // Errores de validacion: se delegan al mapper para mantener la forma
        // unica de la API (envoltura success/error).
        $exceptions->render(function (ValidationException $e, Request $request) {
            return ExceptionMapper::render($e, $request);
        });

        // 404 en rutas de API: JSON, no la pagina HTML de error.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return ExceptionMapper::render($e, $request);
        });
    })
    ->create();
