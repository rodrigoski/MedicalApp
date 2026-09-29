<?php

declare(strict_types=1);

namespace Tests;

use App\Application\Services\AppointmentService;
use App\Application\Services\AvailabilityService;
use App\Application\Services\DoctorService;
use App\Application\Services\PatientService;
use App\Domain\Contracts\ClockInterface;
use App\Infrastructure\System\FrozenClock;
use App\Support\ApiKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base de las pruebas de integracion y de aceptacion.
 *
 * RELOJ CONGELADO (FrozenClock):
 * las reglas de negocio dependen del tiempo ("no se agenda en el pasado", "el
 * limite es de 24 citas por dia"). Si las pruebas usaran el reloj real, una
 * prueba que verifica "no se puede agendar ayer" pasaria un dia y fallaria
 * otro, por ejemplo a las 23:59. Congelar el reloj convierte el tiempo en un
 * dato de entrada mas, y con eso la prueba es DETERMINISTA.
 *
 * El instante elegido (2025-01-07T10:00:00Z, un martes) es deliberado: la
 * politica rechaza la jornada de los domingos, asi que un martes evita tener
 * que depender del dia de la semana.
 *
 * BASE DE DATOS:
 *   - Por defecto, SQLite en memoria: rapido, sin Docker, sin estado compartido.
 *   - Con DB_CONNECTION=pgsql se corre contra PostgreSQL, que es donde se
 *     verifica la restriccion EXCLUDE (no existe en SQLite).
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Instante de referencia de todas las pruebas.
     */
    protected const NOW = '2025-01-07T10:00:00Z';

    protected FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at(self::NOW);

        // El contenedor resuelve el reloj congelado en lugar del reloj del
        // sistema: asi los casos de uso quedan deterministas sin tocar codigo
        // de produccion (no hay un "if (testing)" en ninguna entidad).
        $this->app->instance(ClockInterface::class, $this->clock);
    }

    protected function tearDown(): void
    {
        // Las API keys se cachean estaticamente; sin limpiar, una prueba que
        // cambie la configuracion contaminaria a las siguientes.
        ApiKeys::flush();

        parent::tearDown();
    }

    protected function patientService(): PatientService
    {
        return $this->app->make(PatientService::class);
    }

    protected function doctorService(): DoctorService
    {
        return $this->app->make(DoctorService::class);
    }

    protected function appointmentService(): AppointmentService
    {
        return $this->app->make(AppointmentService::class);
    }

    protected function availabilityService(): AvailabilityService
    {
        return $this->app->make(AvailabilityService::class);
    }

    /**
     * Cabeceras minimas para las pruebas de la API.
     *
     * @return array<string, string>
     */
    protected function apiKeyHeaders(): array
    {
        return [
            'X-Api-Key' => 'test-clinic-key',
            'Accept' => 'application/json',
        ];
    }
}
