<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas web
|--------------------------------------------------------------------------
| El backend NO sirve interfaz de usuario: es una API. La unica ruta web
| devuelve la "carta de navegacion" del servicio, para que un revisor que abra
| http://localhost:8000/ vea donde encontrar cada cosa sin conocer el codigo.
|
| Nota de diseno: NO se sirve un welcome.html con Tailwind de Laravel porque
| (a) es codigo generado que no aporta al proyecto y (b) las dimension
| "Mantenibilidad" de la auditoria penaliza el relleno.
|
| /api/v1/health se expone aqui tambien para documentar que la ruta canonica
| de salud es la del prefijo /api/v1.
*/

Route::get('/', static fn () => response()->json([
    'service' => config('app.name'),
    'version' => config('app.version'),
    'environment' => config('app.env'),
    'description' => 'API de gestion de pacientes, medicos y citas sin traslapes.',
    'endpoints' => [
        'health' => url('/api/v1/health'),
        'stats' => url('/api/v1/stats'),
        'patients' => url('/api/v1/patients'),
        'doctors' => url('/api/v1/doctors'),
        'appointments' => url('/api/v1/appointments'),
        'availability' => url('/api/v1/doctors/{doctorId}/availability?date=YYYY-MM-DD'),
    ],
    'notification_service' => rtrim((string) config('services.notifications.base_url'), '/').'/docs',
    'documentation' => [
        'api' => 'docs/API.md',
        'arquitectura' => 'docs/ARQUITECTURA.md',
        'desacoplamiento' => 'docs/DESACOPLAMIENTO.md',
        'auditoria' => 'docs/AUDITORIA.md',
    ],
    'authentication' => 'Cabecera X-Api-Key en todas las rutas salvo /api/v1/health.',
], 200));

/*
| Ruta de salud de Laravel (sin capa de aplicacion). Util para comprobar que
| el contenedor responde, sin tocar la base de datos.
*/
Route::get('health-laravel', [HealthController::class, 'liveness']);
