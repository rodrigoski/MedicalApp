<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Contracts\AppointmentRepositoryInterface;
use App\Domain\Contracts\ClockInterface;
use App\Domain\Contracts\DoctorRepositoryInterface;
use App\Domain\Contracts\DomainEventRepositoryInterface;
use App\Domain\Contracts\NotificationGatewayInterface;
use App\Domain\Contracts\PatientRepositoryInterface;
use App\Domain\Policies\AppointmentOverlapPolicy;
use App\Infrastructure\Http\Clients\NotificationServiceClient;
use App\Infrastructure\Persistence\Repositories\EloquentAppointmentRepository;
use App\Infrastructure\Persistence\Repositories\EloquentDoctorRepository;
use App\Infrastructure\Persistence\Repositories\EloquentDomainEventRepository;
use App\Infrastructure\Persistence\Repositories\EloquentPatientRepository;
use App\Infrastructure\System\SystemClock;
use Illuminate\Support\ServiceProvider;

/**
 * CONTENEDOR DE DEPENDENCIAS.
 *
 * Este provider es el unico lugar del proyecto donde se conecta una interface
 * con su implementacion concreta. Gracias a la inyeccion de dependencias, cada
 * servicio recibe interfaces y nunca clases concretas de infraestructura.
 *
 * Pregunta de la auditoria: "si cambio la fuente de almacenamiento, cuantos
 * archivos modifico?"  ->  Este archivo y los repositorios. Ni una sola linea
 * de app/Domain ni de app/Application.
 *
 * En las pruebas, tests/Support binds test substitutes re-registran estos
 * bindings, de modo que los casos de uso se prueban sin base de datos.
 */
final class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // --- Servicios de sistema --------------------------------------------
        $this->app->singleton(ClockInterface::class, static fn (): ClockInterface => new SystemClock());

        // --- Politica de dominio --------------------------------------------
        // Configurable por entorno: el techo de citas por dia del medico es una
        // decision operativa de la clinica, no del negocio logico puro.
        $this->app->singleton(
            AppointmentOverlapPolicy::class,
            static fn ($app): AppointmentOverlapPolicy => new AppointmentOverlapPolicy(
                maxAppointmentsPerDoctorPerDay: (int) config(
                    'clinic.max_appointments_per_doctor_per_day',
                    24,
                ),
                minutesOfToleranceForPastDates: (int) config(
                    'clinic.past_date_tolerance_minutes',
                    0,
                ),
            ),
        );

        // --- Puertos de persistencia -> adaptadores Eloquent -----------------
        $this->app->bind(PatientRepositoryInterface::class, EloquentPatientRepository::class);
        $this->app->bind(DoctorRepositoryInterface::class, EloquentDoctorRepository::class);
        $this->app->bind(AppointmentRepositoryInterface::class, EloquentAppointmentRepository::class);
        $this->app->bind(DomainEventRepositoryInterface::class, EloquentDomainEventRepository::class);

        // --- Puerto de integracion externa -> cliente HTTP -------------------
        $this->app->singleton(NotificationGatewayInterface::class, static fn ($app): NotificationGatewayInterface
            => new NotificationServiceClient(
                baseUrl: (string) config('services.notification_service.url'),
                apiKey: (string) config('services.notification_service.api_key'),
                timeoutSeconds: (int) config('services.notification_service.timeout', 4),
                retries: (int) config('services.notification_service.retries', 2),
                clock: $app->make(ClockInterface::class),
            ));
    }
}
