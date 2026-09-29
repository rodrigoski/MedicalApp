<?php

declare(strict_types=1);

namespace App\Http\Exceptions;

use App\Application\Exceptions\ApplicationException;
use App\Domain\Exceptions\AppointmentConflict;
use App\Domain\Exceptions\DuplicateResource;
use App\Domain\Exceptions\InvalidStatusTransition;
use App\Domain\Exceptions\ResourceNotFound;
use App\Http\Support\ApiResponse;
use App\Http\Support\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * TRADUCCION DE EXCEPCIONES A RESPUESTAS HTTP.
 *
 * Decision arquitectural clave y pregunta frecuente en la defensa del proyecto:
 * "¿por que el mapeo de errores a codigos HTTP esta en un solo archivo y no
 * repartido en cada controlador?"
 *
 * Porque el Dominio debe ser agnostico de HTTP (nadie deberia poder saber que
 * existe un protocolo web al escribir una regla de negocio). El Dominio lanza
 * excepciones con un `errorCode` semantico; esta capa, que si conoce HTTP,
 * decide el status y construye la respuesta. Consecuencia: cambiar la politica
 * de errores (por ejemplo, devuelve 422 en vez de 409) se hace en UN archivo,
 * no en 20 controladores.
 */
final class ExceptionMapper
{
    /**
     * Excepcion de dominio -> status HTTP.
     *
     * ESTA TABLA ES LA UNICA FUENTE DEL STATUS. Antes existia tambien una clave
     * `code` aqui, y estaba mal por dos motivos: no se leia en ninguna parte
     * (el `code` real lo devuelve la propia excepcion de dominio) y sus valores
     * (`appointment.conflict`) no coincidian con los que la API emitia de
     * verdad (`doctor_busy`). Un cliente que programara contra esa tabla habria
     * comparado contra una cadena que nunca se emitio. Aqui solo vive el status;
     * el codigo lo tiene la excepcion, que es quien sabe distinguir el motivo
     * concreto.
     *
     * @var array<class-string, int>
     */
    private const STATUS_BY_EXCEPTION = [
        ResourceNotFound::class => 404,
        DuplicateResource::class => 409,
        AppointmentConflict::class => 409,
        InvalidStatusTransition::class => 422,
    ];

    /**
     * Errores de dominio que siempre son 422 (dato invalido), no 500.
     * Se resuelven por prefijo de namespace para no enumerar cada clase.
     */
    private const DOMAIN_VALIDATION_PREFIXES = [
        'App\\Domain\\Exceptions\\Invalid',
    ];

    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        // En rutas web, deja que Laravel maneje el error como siempre.
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $exception instanceof ValidationException => ApiResponse::validationError(
                'Los datos enviados no son validos.',
                $exception->errors(),
            ),

            $exception instanceof AuthenticationException => ApiResponse::error(
                'No autenticado: se requiere una API key valida.',
                'authentication.required',
                401,
            ),

            $exception instanceof AuthorizationException => ApiResponse::error(
                'No tiene permisos para realizar esta operacion.',
                'authorization.forbidden',
                403,
            ),

            $exception instanceof ModelNotFoundException => ApiResponse::error(
                'El recurso solicitado no existe.',
                'resource.not_found',
                404,
            ),

            $exception instanceof NotFoundHttpException => ApiResponse::error(
                'El endpoint solicitado no existe.',
                'route.not_found',
                404,
            ),

            $exception instanceof AppointmentConflict => ApiResponse::error(
                $exception->getMessage(),
                $exception->errorCode(),
                self::STATUS_BY_EXCEPTION[AppointmentConflict::class],
                $exception->context(),
            ),

            $exception instanceof DuplicateResource => ApiResponse::error(
                $exception->getMessage(),
                $exception->errorCode(),
                self::STATUS_BY_EXCEPTION[DuplicateResource::class],
                $exception->context(),
            ),

            $exception instanceof ResourceNotFound => ApiResponse::error(
                $exception->getMessage(),
                $exception->errorCode(),
                self::STATUS_BY_EXCEPTION[ResourceNotFound::class],
                $exception->context(),
            ),

            $exception instanceof InvalidStatusTransition => ApiResponse::error(
                $exception->getMessage(),
                $exception->errorCode(),
                self::STATUS_BY_EXCEPTION[InvalidStatusTransition::class],
                $exception->context(),
            ),

            $exception instanceof ApplicationException => ApiResponse::error(
                $exception->getMessage(),
                $exception->errorCode(),
                $exception->statusCode(),
                $exception->context(),
            ),

            // Limite de tasa. Sin este brazo caeria en el generico de mas abajo
            // y responderia `http.429` con el texto en ingles de Symfony
            // ("Too Many Attempts."). El 429 es una respuesta que un cliente
            // va a tratar de forma automatica, asi que necesita un `code`
            // estable y un mensaje en el idioma del usuario. Se reenvian las
            // cabeceras del framework, que incluyen `Retry-After`: sin ellas el
            // cliente no sabe cuando reintentar y solo puede adivinarlo.
            $exception instanceof ThrottleRequestsException => ApiResponse::error(
                'Demasiadas peticiones. Repita la operacion despues del intervalo indicado en Retry-After.',
                'rate_limit.exceeded',
                429,
                [],
                $exception->getHeaders(),
            ),

            $exception instanceof HttpExceptionInterface => ApiResponse::error(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Error HTTP.',
                'http.'.$exception->getStatusCode(),
                $exception->getStatusCode(),
            ),

            // Value objects invalidos (correo, documento, telefono, intervalo,
            // matricula, genero): el dato es incorrecto -> 422, no 500.
            self::isDomainValidation($exception) => ApiResponse::error(
                $exception->getMessage(),
                method_exists($exception, 'errorCode')
                    ? $exception->errorCode()
                    : 'validation.domain_value_object',
                422,
                method_exists($exception, 'context') ? $exception->context() : [],
            ),

            default => self::renderUnexpected($exception),
        };
    }

    /**
     * 500: nunca se filtra el detalle tecnico al cliente. El detalle va al log
     * con el request_id, para que soporte pueda correlacionarlo.
     */
    private static function renderUnexpected(Throwable $exception): JsonResponse
    {
        report($exception);

        $context = ['exception' => $exception::class];

        // Se lee el identificador YA RESUELTO por el middleware, no la cabecera
        // entrante. La diferencia importa: si el cliente no envio X-Request-Id
        // (lo habitual) y se leyera la cabecera, este 500 se registraria sin
        // ningun identificador, justo cuando mas se necesita para correlacionar.
        $requestId = RequestId::current();

        if ($requestId !== null) {
            $context['request_id'] = $requestId;
        }

        // En modo debug se muestra la excepcion: es util en desarrollo, y en
        // produccion APP_DEBUG=false garantiza que nunca se exponga.
        if (config('app.debug') === true) {
            $context['debug_message'] = $exception->getMessage();
        }

        return ApiResponse::error(
            'Ocurrio un error inesperado. El equipo de soporte ya fue notificado.',
            'server.unexpected_error',
            500,
            $context,
        );
    }

    /**
     * Determina si una excepcion de dominio debe mapearse a 422.
     */
    public static function isDomainValidation(Throwable $exception): bool
    {
        foreach (self::DOMAIN_VALIDATION_PREFIXES as $prefix) {
            if (str_starts_with($exception::class, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
