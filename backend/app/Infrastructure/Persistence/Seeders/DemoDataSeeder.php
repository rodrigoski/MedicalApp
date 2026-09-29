<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Seeders;

use App\Application\DTOs\BookAppointmentData;
use App\Application\DTOs\ChangeStatusData;
use App\Application\DTOs\CreateDoctorData;
use App\Application\DTOs\CreatePatientData;
use App\Application\Services\AppointmentService;
use App\Application\Services\DoctorService;
use App\Application\Services\PatientService;
use App\Domain\Enums\AppointmentStatus;
use App\Domain\Contracts\ClockInterface;
use App\Domain\Entities\Doctor;
use App\Domain\Entities\Patient;
use App\Domain\Exceptions\AppointmentConflict;
use App\Domain\ValueObjects\DocumentId;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\LicenseNumber;
use App\Domain\ValueObjects\PhoneNumber;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * GENERADOR DE DATOS DE DEMOSTRACION (capa de INFRAESTRUCTURA).
 *
 * POR QUE ESTA CLASE NO VIVE EN `Application`:
 * el generador necesita abrir una TRANSACCION de base de datos, y esa es una
 * dependencia tecnica. Ubicarlo en Application obligaria a esa capa a importar
 * `Illuminate\Database`, rompiendo la regla de dependencias que verifica
 * `php artisan architecture:check`. La separacion correcta es:
 *
 *   Application  -> decide QUE datos de negocio se crean (via casos de uso)
 *   Infrastructure-> decide COMO se persisten de forma atomica (transaccion)
 *
 * Usa los casos de uso reales (no SQL directo) para que el conjunto de datos
 * sea siempre coherente con las reglas de negocio. Si se usara INSERT, la cita
 * de demostracion podria violar la regla de no traslape y el conjunto seria
 * irreal.
 */
final class DemoDataSeeder
{
    public function __construct(
        private readonly DoctorService $doctorService,
        private readonly PatientService $patientService,
        private readonly AppointmentService $appointmentService,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{doctors: int, patients: int, appointments_created: int, appointments_rejected: int}
     */
    public function run(): array
    {
        $summary = ['doctors' => 0, 'patients' => 0, 'appointments_created' => 0, 'appointments_rejected' => 0];

        $this->db()->transaction(function () use (&$summary): void {
            $doctors = $this->createDoctors();
            $patients = $this->createPatients();

            $created = 0;
            $rejected = 0;

            // La agenda arranca en el proximo dia de atencion, a la hora en que
            // CADA medico abre su consulta, expresada en la hora local de la
            // clinica y despues llevada a UTC.
            //
            // Importa hacerlo asi: si se fijara "8:00 UTC" a ciegas, en Bogota
            // (UTC-5) eso serian las 3:00 de la madrugada, la agenda se crearia
            // FUERA de la jornada y las 36 citas se rechazarian por `outside_hours`
            // en vez de por traslape. El contador de rechazos debe medir la regla
            // de no traslape, no un error de zona horaria.
            $localDay = $this->nextWorkingDay();

            foreach ($doctors as $doctorIndex => $doctor) {
                $dayCursor = $localDay
                    ->setTime($doctor->workingDayStartHour(), 0)
                    ->utc();

                $firstSlotStart = null;

                for ($slot = 0; $slot < 6; $slot++) {
                    $patient = $patients[($doctorIndex * 3 + $slot) % count($patients)];

                    $start = $dayCursor;
                    $end = $start->addMinutes(30);

                    $dayCursor = $start->addMinutes(45);

                    try {
                        $appointment = $this->appointmentService->book(new BookAppointmentData(
                            patientId: (int) $patient->id(),
                            doctorId: (int) $doctor->id(),
                            timeRange: TimeRange::forAppointment($start, $end),
                            reason: $this->reasonFor($slot),
                        ));

                        // Un tercio de las citas se confirman de inmediato.
                        if ($slot % 3 === 0) {
                            $this->appointmentService->changeStatus(
                                (int) $appointment->id(),
                                new ChangeStatusData(AppointmentStatus::CONFIRMED),
                            );
                        }

                        $firstSlotStart ??= $start;
                        $created++;
                    } catch (AppointmentConflict) {
                        $rejected++;
                    }
                }

                // DEMOSTRACION EXPRESA DE LA REGLA CENTRAL DEL PROYECTO.
                //
                // La agenda anterior esta construida con huecos de 45 minutos y
                // citas de 30, o sea que NO se traslapa consigo misma. Por eso,
                // aqui se intenta a proposito pisar el primer horario ocupado:
                // el intento TIENE que ser rechazado.
                //
                // Sin esta comprobacion, `appointments_rejected` siempre valdria
                // cero y el seed no seria evidencia de nada.
                if ($firstSlotStart === null) {
                    continue;
                }

                try {
                    $this->appointmentService->book(new BookAppointmentData(
                        patientId: (int) $patients[$doctorIndex % count($patients)]->id(),
                        doctorId: (int) $doctor->id(),
                        timeRange: TimeRange::forAppointment(
                            $firstSlotStart,
                            $firstSlotStart->addMinutes(30),
                        ),
                        reason: 'Intento deliberado de traslape (debe fallar)',
                    ));

                    // Si se llegara aqui, la regla de no traslape esta ROTA. Se
                    // lanza para que la transaccion se revierta y el fallo sea
                    // visible en lugar de quedar oculto en un contador.
                    throw new \RuntimeException(
                        'La regla de no traslape no se aplico: se creo una cita '
                        .'sobre un horario ya ocupado (medico '.$doctor->id().').',
                    );
                } catch (AppointmentConflict) {
                    $rejected++;
                }
            }

            $summary = [
                'doctors' => count($doctors),
                'patients' => count($patients),
                'appointments_created' => $created,
                'appointments_rejected' => $rejected,
            ];
        });

        return $summary;
    }

    /**
     * Elimina los datos de demostracion (en orden inverso por FK).
     */
    public function purge(): void
    {
        $this->db()->table('domain_events')->delete();
        $this->db()->table('appointments')->delete();
        $this->db()->table('patients')->delete();
        $this->db()->table('doctors')->delete();
    }

    /**
     * @return list<Doctor>
     */
    private function createDoctors(): array
    {
        // [nombre, matricula, especialidad, hora inicio, hora fin]
        $data = [
            ['Dra. Elena Vargas Ruiz', 'MED-1001', 'Medicina General', 8, 17],
            ['Dr. Carlos Mendoza Paz', 'MED-1002', 'Cardiologia', 9, 17],
            ['Dra. Lucia Fernandez Gil', 'MED-1003', 'Pediatria', 8, 14],
            ['Dr. Andres Quintero Soto', 'MED-1004', 'Dermatologia', 10, 16],
            ['Dra. Sofia Restrepo Nava', 'MED-1005', 'Odontologia', 8, 15],
            ['Dr. Julian Herrerarias', 'MED-1006', 'Ortopedia', 11, 18],
        ];

        $doctors = [];

        foreach ($data as [$name, $license, $specialty, $startHour, $endHour]) {
            $doctors[] = $this->doctorService->create(new CreateDoctorData(
                fullName: $name,
                licenseNumber: LicenseNumber::from($license),
                specialty: $specialty,
                email: Email::from(self::slug($name).'@clinicapp.local'),
                phone: PhoneNumber::from('+57300'.random_int(1000000, 9999999)),
                workingDayStartHour: $startHour,
                workingDayEndHour: $endHour,
                slotDurationMinutes: 30,
            ));
        }

        return $doctors;
    }

    /**
     * @return list<Patient>
     */
    private function createPatients(): array
    {
        // [nombre, documento, fecha de nacimiento, genero, alergias]
        $data = [
            ['Ana Maria Torres Rueda', 'CC1002003004', '1988-04-12', 'F', ['Penicilina']],
            ['Javier Alonso Mejia Ruiz', 'CC1010101010', '1979-11-30', 'M', []],
            ['Patricia Salomon Cruz', 'CC1020304050', '1995-07-22', 'F', ['Latex', 'Ibuprofeno']],
            ['Sebastian Ortiz Lara', 'CC80909090', '2001-02-18', 'M', []],
            ['Claudia Nunez Palacio', 'CE4455667', '1992-09-05', 'F', ['Aspirina']],
            ['Ricardo Barrientos Gil', 'CC7788990011', '1968-01-30', 'M', []],
            ['Monica Adela Rojas Paz', 'CC5566778899', '1985-06-14', 'F', []],
            ['Felipe Cabrera Nieto', 'CC4455667788', '1990-12-01', 'M', []],
            ['Laura Valentina Rios San Juan', 'CC3344556677', '2003-03-19', 'F', []],
            ['Andres Felipe Hoyos Cardona', 'CC2211009988', '1975-08-25', 'M', []],
            ['Gabriela Torres Mendoza', 'CC1122334455', '1999-11-11', 'F', []],
            ['Hernan Dario Pelaez Restrepo', 'CC9988776655', '1982-05-08', 'M', []],
        ];

        $patients = [];

        foreach ($data as $index => [$name, $document, $birthDate, $gender, $allergies]) {
            $patients[] = $this->patientService->create(new CreatePatientData(
                fullName: $name,
                documentId: DocumentId::from($document),
                email: Email::from('paciente'.($index + 1).'@correo.example'),
                phone: PhoneNumber::from('+5731'.random_int(2000000, 9999999)),
                birthDate: CarbonImmutable::parse($birthDate, 'UTC')->startOfDay(),
                gender: $gender,
                address: 'Calle '.random_int(1, 180).' # '.random_int(1, 99).'-'.random_int(1, 4),
                emergencyContactName: 'Contacto de emergencia '.$name,
                emergencyContactPhone: PhoneNumber::from('+5730'.random_int(1000000, 9999999)),
                allergies: $allergies,
            ));
        }

        return $patients;
    }

    /**
     * Primer dia de atencion a partir de manana, a medianoche local.
     *
     * Se salta el domingo porque la politica rechaza la cita si el medico no
     * atiende ese dia: un seed que cayera en domingo crearia una agenda vacia y
     * pareceria un fallo de la regla de no traslape.
     */
    private function nextWorkingDay(): CarbonImmutable
    {
        $timezone = new DateTimeZone((string) config('clinic.default_timezone', 'UTC'));

        // Se usa el reloj inyectado, no `now()`: asi la agenda del seed es
        // coherente con el reloj que usan los casos de uso al validar el pasado.
        $day = CarbonImmutable::instance($this->clock->now())
            ->setTimezone($timezone)
            ->addDay()
            ->startOfDay();

        while ($day->dayOfWeek === CarbonImmutable::SUNDAY) {
            $day = $day->addDay()->startOfDay();
        }

        return $day;
    }

    private function reasonFor(int $slot): string
    {
        $reasons = [
            'Consulta de control',
            'Dolor abdominal',
            'Revision de resultados',
            'Toma de muestra',
            'Valoracion inicial',
            'Seguimiento',
        ];

        return $reasons[$slot % count($reasons)];
    }

    private function slug(string $name): string
    {
        $withoutTitles = str_replace(['Dra. ', 'Dr. '], '', $name);

        return mb_strtolower(
            str_replace(' ', '.', $withoutTitles),
        );
    }

    private function db(): ConnectionInterface
    {
        return DB::connection();
    }
}
