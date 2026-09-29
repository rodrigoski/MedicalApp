<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Entities\Doctor;
use App\Domain\Entities\Patient;
use App\Domain\Exceptions\AppointmentConflict;
use App\Domain\Policies\AppointmentOverlapPolicy;
use App\Domain\ValueObjects\DocumentId;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\LicenseNumber;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PRUEBAS DE LA POLITICA DE TRASLAPE (AppointmentOverlapPolicy).
 *
 * Esta clase implementa la REGLA DE NEGOCIO CENTRAL. Las pruebas verifican cada
 * rama de la regla de forma aislada, sin base de datos ni HTTP: es la
 * definicion ejecutable del enunciado.
 */
final class AppointmentOverlapPolicyTest extends TestCase
{
    private AppointmentOverlapPolicy $policy;

    private Doctor $doctor;

    private Patient $patient;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new AppointmentOverlapPolicy(maxAppointmentsPerDoctorPerDay: 24);
        $this->now = CarbonImmutable::parse('2025-01-07T10:00:00Z');

        $this->doctor = Doctor::register(
            fullName: 'Dra. Elena Vargas',
            licenseNumber: LicenseNumber::from('MED-1001'),
            specialty: 'Medicina General',
            email: Email::from('elena@clinicapp.local'),
            workingDayStartHour: 8,
            workingDayEndHour: 18,
            now: $this->now,
        );

        $this->patient = Patient::register(
            fullName: 'Ana Torres',
            documentId: DocumentId::from('CC1002003004'),
            email: Email::from('ana@example.com'),
            now: $this->now,
        );
    }

    #[Test]
    public function test_permite_agendar_cuando_el_medico_esta_libre(): void
    {
        $requested = $this->range('10:00', '10:30');

        $this->policy->assertCanBook(
            requested: $requested,
            doctor: $this->doctor,
            patient: $this->patient,
            doctorBusyRanges: [$this->range('09:00', '09:30'), $this->range('11:00', '11:30')],
            patientBusyRanges: [],
            existingAppointmentsOnDay: 2,
            now: $this->now,
        );

        // Si no hay excepcion, la prueba pasa. Se usa expectNotToPerformAssertions
        // implicito: la ausencia de error ES la asercion.
        self::assertTrue(true, 'No debio lanzar excepcion.');
    }

    #[Test]
    public function test_rechaza_traslape_con_otra_cita_del_medico(): void
    {
        $this->expectException(AppointmentConflict::class);
        $this->expectExceptionMessageMatches('/ya tiene una cita/');

        $this->policy->assertCanBook(
            requested: $this->range('10:00', '10:30'),
            doctor: $this->doctor,
            patient: $this->patient,
            doctorBusyRanges: [$this->range('10:15', '10:45')],
            patientBusyRanges: [],
            existingAppointmentsOnDay: 1,
            now: $this->now,
        );
    }

    #[Test]
    public function test_el_motivo_del_conflicto_es_doctor_busy(): void
    {
        try {
            $this->policy->assertCanBook(
                requested: $this->range('10:00', '10:30'),
                doctor: $this->doctor,
                patient: $this->patient,
                doctorBusyRanges: [$this->range('10:15', '10:45')],
                patientBusyRanges: [],
                existingAppointmentsOnDay: 1,
                now: $this->now,
            );

            self::fail('Se esperaba AppointmentConflict.');
        } catch (AppointmentConflict $e) {
            self::assertSame(AppointmentConflict::DOCTOR_BUSY, $e->reason());
            self::assertSame('appointment.conflict.doctor_busy', $e->errorCode());
            self::assertArrayHasKey('conflicting_starts_at', $e->context());
        }
    }

    #[Test]
    public function test_rechaza_traslape_por_motivo_del_paciente(): void
    {
        try {
            $this->policy->assertCanBook(
                requested: $this->range('10:00', '10:30'),
                doctor: $this->doctor,
                patient: $this->patient,
                doctorBusyRanges: [],
                patientBusyRanges: [$this->range('10:00', '11:00')],
                existingAppointmentsOnDay: 0,
                now: $this->now,
            );

            self::fail('Se esperaba AppointmentConflict.');
        } catch (AppointmentConflict $e) {
            self::assertSame(AppointmentConflict::PATIENT_BUSY, $e->reason());
        }
    }

    #[Test]
    public function test_permite_citas_consecutivas_que_se_tocan(): void
    {
        // 10:00-10:30 y 10:30-11:00 comparten el instante 10:30 pero no se
        // traslapan. Esta es una decision de negocio explicita.
        $this->policy->assertCanBook(
            requested: $this->range('10:30', '11:00'),
            doctor: $this->doctor,
            patient: $this->patient,
            doctorBusyRanges: [$this->range('10:00', '10:30')],
            patientBusyRanges: [],
            existingAppointmentsOnDay: 1,
            now: $this->now,
        );

        self::assertTrue(true, 'Las citas consecutivas deben permitirse.');
    }

    #[Test]
    public function test_rechaza_agendar_en_el_pasado(): void
    {
        try {
            $this->policy->assertCanBook(
                requested: $this->range('08:00', '08:30'),
                doctor: $this->doctor,
                patient: $this->patient,
                doctorBusyRanges: [],
                patientBusyRanges: [],
                existingAppointmentsOnDay: 0,
                now: $this->now,
            );

            self::fail('Se esperaba AppointmentConflict.');
        } catch (AppointmentConflict $e) {
            self::assertSame(AppointmentConflict::PAST_DATE, $e->reason());
            self::assertStringContainsString('en el pasado', $e->getMessage());
        }
    }

    #[Test]
    public function test_rechaza_paciente_inactivo(): void
    {
        $inactive = Patient::register(
            fullName: 'Paciente Inactivo',
            documentId: DocumentId::from('CC9999999999'),
            now: $this->now,
        );
        $inactive->deactivate($this->now);

        try {
            $this->policy->assertCanBook(
                requested: $this->range('10:00', '10:30'),
                doctor: $this->doctor,
                patient: $inactive,
                doctorBusyRanges: [],
                patientBusyRanges: [],
                existingAppointmentsOnDay: 0,
                now: $this->now,
            );

            self::fail('Se esperaba AppointmentConflict.');
        } catch (AppointmentConflict $e) {
            self::assertSame(AppointmentConflict::PATIENT_INACTIVE, $e->reason());
        }
    }

    #[Test]
    public function test_rechaza_medico_inactivo(): void
    {
        $this->doctor->deactivate($this->now);

        try {
            $this->policy->assertCanBook(
                requested: $this->range('10:00', '10:30'),
                doctor: $this->doctor,
                patient: $this->patient,
                doctorBusyRanges: [],
                patientBusyRanges: [],
                existingAppointmentsOnDay: 0,
                now: $this->now,
            );

            self::fail('Se esperaba AppointmentConflict.');
        } catch (AppointmentConflict $e) {
            self::assertSame(AppointmentConflict::DOCTOR_INACTIVE, $e->reason());
        }
    }

    #[Test]
    public function test_rechaza_superar_el_techo_diario(): void
    {
        $policy = new AppointmentOverlapPolicy(maxAppointmentsPerDoctorPerDay: 3);

        $this->expectException(AppointmentConflict::class);
        $this->expectExceptionMessageMatches('/maximo de 3 citas/');

        $policy->assertCanBook(
            requested: $this->range('10:00', '10:30'),
            doctor: $this->doctor,
            patient: $this->patient,
            doctorBusyRanges: [],
            patientBusyRanges: [],
            existingAppointmentsOnDay: 3,
            now: $this->now,
        );
    }

    #[Test]
    public function test_verifica_primero_el_pasado_que_el_traslape(): void
    {
        // Si ambas condiciones se incumplen, se reporta la mas relevante para
        // el usuario (el pasado), no la tecnica.
        try {
            $this->policy->assertCanBook(
                requested: $this->range('08:00', '08:30'),
                doctor: $this->doctor,
                patient: $this->patient,
                doctorBusyRanges: [$this->range('08:00', '08:30')],
                patientBusyRanges: [],
                existingAppointmentsOnDay: 0,
                now: $this->now,
            );

            self::fail('Se esperaba AppointmentConflict.');
        } catch (AppointmentConflict $e) {
            self::assertSame(AppointmentConflict::PAST_DATE, $e->reason());
        }
    }

    /*
    |----------------------------------------------------------------------
    | JORNADA LABORAL
    |----------------------------------------------------------------------
    */

    #[Test]
    public function test_rechaza_una_cita_que_empieza_antes_de_la_jornada(): void
    {
        // 11:00 es FUTURO respecto a `now` (10:00). Importa: la politica
        // comprueba el pasado ANTES que la jornada, de modo que una cita a las
        // 07:00 devolveria PAST_DATE y esta prueba comprobaria otra regla sin
        // querer.
        try {
            $this->policy->assertCanBook(
                requested: $this->range('11:00', '11:30'),
                doctor: $this->doctor,
                patient: $this->patient,
                doctorBusyRanges: [],
                patientBusyRanges: [],
                existingAppointmentsOnDay: 0,
                now: $this->now,
            );

            self::fail('Se esperaba AppointmentConflict.');
        } catch (AppointmentConflict $e) {
            self::assertSame(AppointmentConflict::OUTSIDE_HOURS, $e->reason());
        }
    }

    #[Test]
    public function test_rechaza_una_cita_que_termina_despues_de_la_jornada(): void
    {
        $this->expectException(AppointmentConflict::class);
        $this->expectExceptionMessageMatches('/fuera de la jornada/');

        $this->policy->assertCanBook(
            requested: $this->range('17:45', '18:30'),
            doctor: $this->doctor,
            patient: $this->patient,
            doctorBusyRanges: [],
            patientBusyRanges: [],
            existingAppointmentsOnDay: 0,
            now: $this->now,
        );
    }

    #[Test]
    public function test_rechaza_agendar_en_domingo(): void
    {
        // 2025-01-12 es domingo: el medico no atiende.
        $domingo = TimeRange::from(
            CarbonImmutable::parse('2025-01-12T14:00:00Z'),
            CarbonImmutable::parse('2025-01-12T14:30:00Z'),
        );

        try {
            $this->policy->assertCanBook(
                requested: $domingo,
                doctor: $this->doctor,
                patient: $this->patient,
                doctorBusyRanges: [],
                patientBusyRanges: [],
                existingAppointmentsOnDay: 0,
                now: $this->now,
            );

            self::fail('Se esperaba AppointmentConflict.');
        } catch (AppointmentConflict $e) {
            self::assertSame(AppointmentConflict::OUTSIDE_HOURS, $e->reason());
            self::assertStringContainsString('no atiende ese dia', $e->getMessage());
        }
    }

    #[Test]
    public function test_la_jornada_se_valida_en_la_hora_local_de_la_clinica(): void
    {
        // Bogota es UTC-5. El medico atiende de 8 a 18 HORA LOCAL, es decir de
        // 13:00 a 23:00 UTC. Una cita a las 14:00 UTC son las 09:00 locales y es
        // valida, aunque en UTC "parezca" estar fuera del rango 8-18.
        $policy = new AppointmentOverlapPolicy(
            maxAppointmentsPerDoctorPerDay: 24,
            clinicTimezone: 'America/Bogota',
        );

        $policy->assertCanBook(
            requested: TimeRange::from(
                CarbonImmutable::parse('2025-01-07T14:00:00Z'),
                CarbonImmutable::parse('2025-01-07T14:30:00Z'),
            ),
            doctor: $this->doctor,
            patient: $this->patient,
            doctorBusyRanges: [],
            patientBusyRanges: [],
            existingAppointmentsOnDay: 0,
            now: $this->now,
        );

        self::assertTrue(true, '09:00 local debe estar dentro de la jornada 8-18.');

        // Y a las 12:00 UTC son las 07:00 locales: antes de abrir.
        $this->expectException(AppointmentConflict::class);

        $policy->assertCanBook(
            requested: TimeRange::from(
                CarbonImmutable::parse('2025-01-07T12:00:00Z'),
                CarbonImmutable::parse('2025-01-07T12:30:00Z'),
            ),
            doctor: $this->doctor,
            patient: $this->patient,
            doctorBusyRanges: [],
            patientBusyRanges: [],
            existingAppointmentsOnDay: 0,
            now: $this->now,
        );
    }

    private function range(string $start, string $end): TimeRange
    {
        return TimeRange::from(
            CarbonImmutable::parse('2025-01-07 '.$start, 'UTC'),
            CarbonImmutable::parse('2025-01-07 '.$end, 'UTC'),
        );
    }
}
