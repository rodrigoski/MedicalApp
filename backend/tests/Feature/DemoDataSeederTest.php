<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Persistence\Models\AppointmentModel;
use App\Infrastructure\Persistence\Models\DoctorModel;
use App\Infrastructure\Persistence\Models\PatientModel;
use App\Infrastructure\Persistence\Seeders\DemoDataSeeder;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PRUEBA DEL GENERADOR DE DATOS DE DEMOSTRACION.
 *
 * No es una prueba cosmetica: el seed es la EVIDENCIA que se ejecuta delante de
 * un jurado. Si el seed creara citas sin que la regla de no traslape rechazara
 * ninguna, se estaria afirmando una garantia que nadie verifico. Por eso se
 * comprueban las tres cosas que el seed promete:
 *
 *   1. Crea medicos, pacientes y citas.
 *   2. Rechaza el intento deliberado de traslape (regla demostrada).
 *   3. No deja la agenda fuera de la jornada de cada medico.
 */
final class DemoDataSeederTest extends TestCase
{
    #[Test]
    public function test_genera_medicos_pacientes_y_citas(): void
    {
        $summary = $this->seed();

        self::assertSame(6, $summary['doctors']);
        self::assertSame(12, $summary['patients']);
        self::assertSame(36, $summary['appointments_created']);
        self::assertSame(6, DoctorModel::query()->count());
        self::assertSame(12, PatientModel::query()->count());
        self::assertSame(36, AppointmentModel::query()->count());
    }

    #[Test]
    public function test_el_seed_rechaza_el_traslape_deliberado(): void
    {
        $summary = $this->seed();

        // Un rechazo por medico: el intento de pisar el primer horario ocupado.
        // Si este numero fuera 0, el seed habria creado las citas superpuestas y
        // la regla central del proyecto estaria rota.
        self::assertSame(6, $summary['appointments_rejected']);
    }

    #[Test]
    public function test_la_agenda_generada_respeta_la_jornada_de_cada_medico(): void
    {
        $this->seed();

        $timezone = new DateTimeZone((string) config('clinic.default_timezone'));

        foreach (DoctorModel::query()->get() as $doctor) {
            $appointments = AppointmentModel::query()
                ->where('doctor_id', $doctor->id)
                ->get(['starts_at', 'ends_at']);

            self::assertNotEmpty($appointments, 'Cada medico debe tener agenda.');

            foreach ($appointments as $appointment) {
                $localStart = $appointment->starts_at->setTimezone($timezone);
                $localEnd = $appointment->ends_at->setTimezone($timezone);

                self::assertGreaterThanOrEqual(
                    $doctor->working_day_start_hour,
                    (int) $localStart->format('G'),
                    'La cita empieza antes de que abra el consultorio.',
                );
                self::assertLessThanOrEqual(
                    $doctor->working_day_end_hour,
                    (int) $localEnd->format('G'),
                    'La cita termina despues de que cierre el consultorio.',
                );
                self::assertNotSame(
                    7,
                    (int) $localStart->format('N'),
                    'No se agenda un domingo.',
                );
            }
        }
    }

    #[Test]
    public function test_el_seed_es_idempotente_tras_purgar(): void
    {
        $seeder = $this->seeder();
        $seeder->run();
        $seeder->purge();

        self::assertSame(0, DoctorModel::query()->count());
        self::assertSame(0, PatientModel::query()->count());
        self::assertSame(0, AppointmentModel::query()->count());

        $summary = $seeder->run();

        self::assertSame(36, $summary['appointments_created']);
    }

    /**
     * @return array{doctors: int, patients: int, appointments_created: int, appointments_rejected: int}
     */
    private function seed(): array
    {
        return $this->seeder()->run();
    }

    private function seeder(): DemoDataSeeder
    {
        return $this->app->make(DemoDataSeeder::class);
    }
}
