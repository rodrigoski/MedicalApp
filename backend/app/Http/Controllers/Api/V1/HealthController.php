<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\AppointmentService;
use App\Application\Services\DoctorService;
use App\Application\Services\EventPublisher;
use App\Domain\Contracts\DoctorRepositoryInterface;
use App\Domain\Contracts\PatientRepositoryInterface;
use App\Http\Controllers\ApiController;
use App\Http\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints de estado y observabilidad.
 *
 * - /api/v1/health   -> usado por Docker y por el balanceador para decidir si
 *                      la instancia puede recibir trafico. Debe responder rapido
 *                      y sin depender de servicios externos, salvo la BD.
 * - /api/v1/stats    -> indicadores de negocio para el panel.
 */
final class HealthController extends ApiController
{
    public function __construct(
        private readonly DoctorRepositoryInterface $doctors,
        private readonly PatientRepositoryInterface $patients,
        private readonly AppointmentService $appointments,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * GET /api/v1/health
     *
     * Liveness + readiness combinados. Si la BD no responde, devuelve 503 para
     * que Docker/Nginx dejen de enrutar trafico hacia esta instancia.
     *
     * PARAMETRO `?deep=1` (readiness profunda): ademas de la base de datos,
     * comprueba el microservicio de notificaciones. NO se usa en el
     * HEALTHCHECK de Docker, y esta es una decision importante:
     *
     *   Si el healthcheck de la API dependiera del microservicio y este ultimo
     *   tardara en arrancar, la API se declararia sana y el gateway (que
     *   depende de la API sana) nunca receberia trafico: un fallo de un
     *   servicio terciario tumbaria el sistema completo. Un microservicio de
     *   notificaciones caido DEGRADA el servicio, no lo tumba.
     */
    public function health(Request $request): JsonResponse
    {
        $checks = [];
        $healthy = true;

        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $healthy = false;
            $checks['database'] = [
                'status' => 'error',
                // No se filtra el mensaje crudo: puede contener credenciales
                // (DSN) o detalles internos de la topologia.
                'message' => 'No se pudo ejecutar la consulta de verificacion.',
            ];
        }

        if ($request->boolean('deep')) {
            $checks['notification_service'] = [
                'status' => $this->events->isGatewayReachable() ? 'ok' : 'degraded',
            ];
        }

        return ApiResponse::success([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'service' => config('app.name'),
            'version' => config('app.version'),
            'environment' => config('app.env'),
            'timestamp' => now('UTC')->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * GET /health-laravel
     *
     * Liveness MINIMA: no consulta la base de datos ni el microservicio.
     * Responde "el proceso de PHP esta vivo". Util para separar un fallo de
     * infraestructura (arranque) de un fallo de dependencia (PostgreSQL caido).
     */
    public function liveness(): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'alive',
            'service' => config('app.name'),
            'php' => PHP_VERSION,
            'timestamp' => now('UTC')->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/stats
     *
     * Indicadores agregados. Se calcularan con consultas agrupadas en el
     * repositorio, no trayendo las citas a memoria y contando en PHP.
     */
    public function stats(): JsonResponse
    {
        $appointments = $this->appointments->statistics();

        $byStatus = $this->appointments->countByStatus();

        $specialties = DB::table('doctors')
            ->whereNull('deleted_at')
            ->selectRaw('specialty, COUNT(*) as total')
            ->groupBy('specialty')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(static fn ($row): array => [
                'specialty' => (string) $row->specialty,
                'doctors' => (int) $row->total,
            ])
            ->all();

        return $this->ok([
            'patients' => [
                'total' => DB::table('patients')->whereNull('deleted_at')->count(),
                'deleted' => DB::table('patients')->whereNotNull('deleted_at')->count(),
            ],
            'doctors' => [
                'total' => $this->doctors->countAll(),
            ],
            'appointments' => $appointments + ['by_status' => $byStatus],
            'top_specialties' => $specialties,
            'events' => $this->events->diagnostics(),
        ]);
    }
}
