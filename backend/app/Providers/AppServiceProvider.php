<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Services\AvailabilityService;
use App\Domain\Policies\AppointmentOverlapPolicy;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerDomainPolicies();
        $this->registerTimezoneAwareServices();
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * La politica de traslape recibe sus PARAMETROS OPERATIVOS desde la
     * configuracion, no desde codigo.
     *
     * Aqui se cumple la division que sostiene la arquitectura:
     *   - el Dominio define COMO se decide (sin configuracion, sin framework),
     *   - la capa de composicion inyecta los VALORES de este hospital.
     *
     * Asi, cambiar la jornada o el techo diario no obliga a tocar el Dominio, y
     * un hospital con turno de 24 h no necesita una copia del proyecto.
     */
    private function registerDomainPolicies(): void
    {
        $this->app->singleton(
            AppointmentOverlapPolicy::class,
            static fn (): AppointmentOverlapPolicy => new AppointmentOverlapPolicy(
                maxAppointmentsPerDoctorPerDay: (int) config(
                    'clinic.max_appointments_per_doctor_per_day',
                    24,
                ),
                minutesOfToleranceForPastDates: (int) config(
                    'clinic.past_date_tolerance_minutes',
                    0,
                ),
                clinicTimezone: (string) config('clinic.default_timezone', 'UTC'),
            ),
        );
    }

    /**
     * La disponibilidad debe mirar el MISMO reloj de pared que la politica de
     * traslape. Si el calendario se construyera en UTC y la validacion en hora
     * local, el sistema ofreceria huecos que despues rechazaria al reservarlos.
     *
     * El binding es POR CONTEXTO: solo se inyecta la zona; el resto de
     * dependencias las resuelve el contenedor como siempre.
     */
    private function registerTimezoneAwareServices(): void
    {
        $this->app->when(AvailabilityService::class)
            ->needs('$clinicTimezone')
            ->give(static fn (): string => (string) config('clinic.default_timezone', 'UTC'));
    }

    /**
     * Politicas de limitacion de tasa por endpoint.
     *
     * CRITERIO DE SEGURIDAD (OWASP API4 - Unrestricted Resource Consumption):
     * sin esto, un atacante podria inundar el endpoint de disponibilidad de
     * agenda, que es el mas caro (calcula slots). Los limites se definen por
     * ruta, no de forma global, para no penalizar al resto de la API.
     */
    private function configureRateLimiting(): void
    {
        // Escrituras (create/update/delete) requieren API key.
        RateLimiter::for('writes', static function (Request $request): Limit {
            return [
                Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()),
                Limit::perMinute(120)->by('global-writes:'.$request->ip()),
            ];
        });

        // Consultas de disponibilidad: costosas (calculan slots) y muy consultados.
        RateLimiter::for('availability', static function (Request $request): Limit {
            return [
                Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()),
            ];
        });

        // Lecturas generales.
        RateLimiter::for('reads', static function (Request $request): Limit {
            return [
                Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()),
            ];
        });

        // Recepcion de eventos desde el microservicio (mutua).
        RateLimiter::for('events', static function (Request $request): Limit {
            return [
                Limit::perMinute(300)->by($request->ip()),
            ];
        });

        // Ping de salud: no debe bloquear nunca.
        RateLimiter::for('health', static function (): Limit {
            return Limit::perMinute(60);
        });
    }
}
