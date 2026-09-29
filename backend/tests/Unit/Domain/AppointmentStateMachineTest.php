<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Entities\Appointment;
use App\Domain\Enums\AppointmentStatus;
use App\Domain\Exceptions\InvalidStatusTransition;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PRUEBAS DE LA MAQUINA DE ESTADOS DE LA CITA.
 *
 * Verifica el modelo de estado exigido por el enunciado (pendiente,
 * confirmada, cancelada) y sus transiciones validas e invalidas.
 */
final class AppointmentStateMachineTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2025-01-07T10:00:00Z');
    }

    #[Test]
    public function test_una_cita_nace_pendiente(): void
    {
        $appointment = $this->newAppointment();

        self::assertSame(AppointmentStatus::PENDING, $appointment->status());
        self::assertTrue($appointment->isPending());
        self::assertTrue($appointment->occupiesSchedule());
        self::assertNull($appointment->confirmedAt());
    }

    #[Test]
    public function test_pendiente_puede_confirmarse(): void
    {
        $appointment = $this->newAppointment();
        $appointment->confirm($this->now);

        self::assertSame(AppointmentStatus::CONFIRMED, $appointment->status());
        self::assertNotNull($appointment->confirmedAt());
        self::assertTrue($appointment->occupiesSchedule());
    }

    #[Test]
    public function test_confirmada_puede_cancelarse_con_motivo(): void
    {
        $appointment = $this->newAppointment();
        $appointment->confirm($this->now);
        $appointment->cancel('El paciente no asistio', $this->now);

        self::assertSame(AppointmentStatus::CANCELLED, $appointment->status());
        self::assertSame('El paciente no asistio', $appointment->cancellationReason());
        self::assertNotNull($appointment->cancelledAt());
    }

    #[Test]
    public function test_una_cita_cancelada_libera_el_horario(): void
    {
        // ESTA ES LA REGLA CLAVE: una cita cancelada deja de bloquear agenda.
        $appointment = $this->newAppointment();
        $appointment->cancel('Reprogramada', $this->now);

        self::assertFalse($appointment->occupiesSchedule());
        self::assertFalse($appointment->status()->isActive());
    }

    /**
     * @return array<string, array{0: AppointmentStatus, 1: AppointmentStatus, 2: bool}>
     */
    public static function transitionMatrix(): array
    {
        return [
            'pendiente -> confirmada' => [AppointmentStatus::PENDING, AppointmentStatus::CONFIRMED, true],
            'pendiente -> cancelada' => [AppointmentStatus::PENDING, AppointmentStatus::CANCELLED, true],
            'confirmada -> cancelada' => [AppointmentStatus::CONFIRMED, AppointmentStatus::CANCELLED, true],
            'confirmada -> pendiente' => [AppointmentStatus::CONFIRMED, AppointmentStatus::PENDING, true],
            'cancelada -> confirmada (INVALIDA)' => [AppointmentStatus::CANCELLED, AppointmentStatus::CONFIRMED, false],
            'cancelada -> pendiente (INVALIDA)' => [AppointmentStatus::CANCELLED, AppointmentStatus::PENDING, false],
            'pendiente -> pendiente (INVALIDA)' => [AppointmentStatus::PENDING, AppointmentStatus::PENDING, false],
            'confirmada -> confirmada (INVALIDA)' => [AppointmentStatus::CONFIRMED, AppointmentStatus::CONFIRMED, false],
        ];
    }

    #[Test]
    #[DataProvider('transitionMatrix')]
    public function test_matriz_de_transiciones(
        AppointmentStatus $from,
        AppointmentStatus $to,
        bool $allowed,
    ): void {
        self::assertSame(
            $allowed,
            $from->canTransitionTo($to),
            sprintf('Transicion %s -> %s', $from->value, $to->value),
        );
    }

    #[Test]
    public function test_cancelled_es_un_estado_terminal(): void
    {
        self::assertTrue(AppointmentStatus::CANCELLED->isTerminal());
        self::assertSame([], AppointmentStatus::CANCELLED->allowedTransitions());

        $appointment = $this->newAppointment();
        $appointment->cancel('Motivo', $this->now);

        $this->expectException(InvalidStatusTransition::class);
        $this->expectExceptionMessageMatches('/es terminal/');

        $appointment->confirm($this->now);
    }

    #[Test]
    public function test_transicion_invalida_informa_las_permitidas(): void
    {
        $appointment = $this->newAppointment();

        try {
            $appointment->reopen($this->now);
            self::fail('Se esperaba InvalidStatusTransition.');
        } catch (InvalidStatusTransition $e) {
            self::assertSame('appointment.invalid_status_transition', $e->errorCode());
            self::assertStringContainsString('Pendiente', $e->getMessage());
            self::assertSame(
                ['confirmed', 'cancelled'],
                $e->context()['allowed'],
            );
        }
    }

    #[Test]
    public function test_reprogramar_conserva_el_estado(): void
    {
        $appointment = $this->newAppointment();
        $appointment->confirm($this->now);

        $nuevo = TimeRange::from('2025-01-08T14:00:00Z', '2025-01-08T14:30:00Z');
        $appointment->reschedule($nuevo, $this->now);

        self::assertSame(AppointmentStatus::CONFIRMED, $appointment->status());
        self::assertSame('2025-01-08T14:00:00+00:00', $appointment->timeRange()->startAt()->toIso8601String());
        self::assertNotNull($appointment->confirmedAt(), 'La reprogramacion no debe borrar la confirmacion.');
    }

    private function newAppointment(): Appointment
    {
        return Appointment::book(
            patientId: 1,
            doctorId: 1,
            timeRange: TimeRange::from('2025-01-07T11:00:00Z', '2025-01-07T11:30:00Z'),
            reason: 'Consulta de control',
            now: $this->now,
        );
    }
}
