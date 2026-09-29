<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\DTOs\BookAppointmentData;
use App\Application\DTOs\CreateDoctorData;
use App\Application\DTOs\CreatePatientData;
use App\Domain\Enums\AppointmentStatus;
use App\Domain\ValueObjects\DocumentId;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\LicenseNumber;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PRUEBAS DE ACEPTACION DE LA REGLA DE NO TRASLAPE (vía HTTP).
 *
 * Estas pruebas son la EVIDENCIA de exposicion: no preguntan "¿la politica
 * dice que no?", sino "¿qué le devuelve el USUARIO cuando intenta agendar dos
 * citas que se cruzan?". Verifican el camino completo: HTTP -> FormRequest ->
 * caso de uso -> politica -> repositorio -> resource -> ExceptionMapper.
 *
 * El codigo de respuesta 409 (Conflict) y el campo `error.code` con valor
 * `appointment.conflict.doctor_busy` son el CONTRATO PUBLICO de la regla. Si
 * alguien cambia ese codigo, estas pruebas lo detectan.
 */
final class AppointmentApiTest extends TestCase
{
    private const HOY = '2025-01-07';

    #[Test]
    public function test_crea_una_cita_y_devuelve_201(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        $response = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
            'reason' => 'Consulta de control',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.appointment.status', AppointmentStatus::PENDING->value);
        $response->assertJsonPath('data.appointment.patient_id', $patientId);
        $response->assertJsonPath('data.appointment.time_range.duration_minutes', 30);
    }

    #[Test]
    public function test_rechaza_con_409_una_cita_que_se_traslapa_con_otra_del_mismo_medico(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
        ])->assertCreated();

        // Segunda cita que se CRUZA con la primera (14:15 - 14:45).
        $response = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:15:00Z',
            'ends_at' => self::HOY.'T14:45:00Z',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'appointment.conflict.doctor_busy');
        $response->assertJsonStructure(['error' => ['detail', 'code', 'status', 'context']]);
        $response->assertJsonPath('error.status', 409);
        $response->assertJsonPath('error.context.reason', 'doctor_busy');
        $response->assertJsonPath('error.context.conflicting_starts_at', '2025-01-07T14:00:00+00:00');
    }

    #[Test]
    public function test_permite_citas_consecutivas_que_se_tocan(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
        ])->assertCreated();

        // 14:30-15:00 comparte el instante 14:30 con la anterior, pero no se
        // traslapa. Es el caso limite que define el intervalo semiabierto.
        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:30:00Z',
            'ends_at' => self::HOY.'T15:00:00Z',
        ])->assertCreated();
    }

    #[Test]
    public function test_el_horario_se_libera_al_cancelar_la_cita(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        $primera = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
        ])->assertCreated();

        $appointmentId = (int) $primera->json('data.appointment.id');

        // Mientras la cita esta pendiente, el horario esta ocupado.
        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
        ])->assertStatus(409);

        $this->withHeaders($this->apiKeyHeaders())
            ->deleteJson("/api/v1/appointments/{$appointmentId}?reason=El paciente no asistio")
            ->assertOk()
            ->assertJsonPath('data.appointment.status', AppointmentStatus::CANCELLED->value);

        // Tras cancelar, el mismo horario vuelve a estar disponible.
        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
        ])->assertCreated();
    }

    #[Test]
    public function test_rechaza_agendar_en_el_pasado(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        $response = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => '2020-01-01T10:00:00Z',
            'ends_at' => '2020-01-01T10:30:00Z',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'appointment.conflict.past_date');
    }

    #[Test]
    public function test_rechaza_una_cita_fuera_de_la_jornada_del_medico(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        // La clinica opera en America/Bogota (UTC-5) con jornada de 8 a 18
        // LOCALES, es decir de 13:00 a 23:00 UTC. Las 11:00 UTC son las 06:00
        // locales: todavia no abrio, y no esta en el pasado.
        $response = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T11:00:00Z',
            'ends_at' => self::HOY.'T11:30:00Z',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'appointment.conflict.outside_hours');
    }

    #[Test]
    public function test_rechaza_una_cita_cuando_el_paciente_ya_esta_ocupado(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();
        [, $otroDoctorId] = $this->crearPacienteYMedico('CC9999999999', 'MED-9999');

        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
        ])->assertCreated();

        // Mismo paciente, otro medico, mismo horario: el paciente no puede estar
        // en dos consultorios a la vez.
        $response = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $otroDoctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'appointment.conflict.patient_busy');
    }

    #[Test]
    public function test_reprogramar_tambien_valida_el_traslape(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        $primera = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
        ])->assertCreated();

        // Una segunda cita a las 15:00, contigua a la primera.
        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T15:00:00Z',
            'ends_at' => self::HOY.'T15:30:00Z',
        ])->assertCreated();

        // Se intenta mover la primera cita encima de la segunda.
        $response = $this->withHeaders($this->apiKeyHeaders())->postJson(
            '/api/v1/appointments/'.(int) $primera->json('data.appointment.id').'/reschedule',
            [
                'starts_at' => self::HOY.'T15:00:00Z',
                'ends_at' => self::HOY.'T15:30:00Z',
            ],
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'appointment.conflict.doctor_busy');
    }

    #[Test]
    public function test_reprogramar_a_un_hueco_libre_actualiza_el_intervalo(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        $cita = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
        ])->assertCreated();

        $this->withHeaders($this->apiKeyHeaders())->postJson(
            '/api/v1/appointments/'.(int) $cita->json('data.appointment.id').'/reschedule',
            [
                'starts_at' => self::HOY.'T16:00:00Z',
                'ends_at' => self::HOY.'T16:30:00Z',
                'reason' => 'El paciente cambio de turno',
            ],
        )
            ->assertOk()
            ->assertJsonPath('data.appointment.starts_at', self::HOY.'T16:00:00+00:00');
    }

    #[Test]
    public function test_transicion_de_estado_invalida_devuelve_422(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        $cita = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
        ])->assertCreated();

        $id = (int) $cita->json('data.appointment.id');

        $this->withHeaders($this->apiKeyHeaders())
            ->patchJson("/api/v1/appointments/{$id}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.appointment.status', 'confirmed');

        // CANCELADA es terminal: no se puede volver a CONFIRMADA.
        $this->withHeaders($this->apiKeyHeaders())
            ->patchJson("/api/v1/appointments/{$id}/status", [
                'status' => 'cancelled',
                'reason' => 'El paciente no asistio',
            ])
            ->assertOk()
            ->assertJsonPath('data.appointment.status', 'cancelled');

        $this->withHeaders($this->apiKeyHeaders())
            ->patchJson("/api/v1/appointments/{$id}/status", ['status' => 'confirmed'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'appointment.invalid_status_transition');
    }

    #[Test]
    public function test_la_disponibilidad_refleja_las_citas_existentes(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T14:00:00Z',
            'ends_at' => self::HOY.'T14:30:00Z',
        ])->assertCreated();

        $response = $this->withHeaders($this->apiKeyHeaders())
            ->getJson("/api/v1/doctors/{$doctorId}/availability?date=".self::HOY)
            ->assertOk();

        $response->assertJsonStructure([
            'data' => ['availability' => [
                'doctor_id',
                'date',
                'slot_minutes',
                'summary' => ['total_slots', 'free_slots', 'occupied_slots', 'occupancy_percentage'],
                'free_slots',
                'occupied_slots',
            ]],
        ]);
        $response->assertJsonPath('data.availability.doctor_id', $doctorId);
        $response->assertJsonPath('data.availability.summary.occupied_slots', 1);
        // Jornada de 8 a 18 LOCALES con slots de 30 min = 20 slots. Que sean 20
        // y no 21 demuestra que la ventana se calculo en hora local (13:00-23:00
        // UTC) y no en UTC (lo que daria 20 slots equivocado desfasados 5 horas).
        $response->assertJsonPath('data.availability.summary.total_slots', 20);

        // El slot de 14:00-14:30 debe figurar como ocupado y no entre los libres.
        $this->assertSame(
            '2025-01-07T14:00:00+00:00',
            $response->json('data.availability.occupied_slots.0.starts_at'),
        );
        $this->assertNotContains(
            '2025-01-07T14:00:00+00:00',
            array_column((array) $response->json('data.availability.free_slots'), 'starts_at'),
        );

        // 08:00 locales son las 13:00 UTC: el primer hueco offered por el
        // calendario es exactamente el primero que la politica de traslape
        // aceptaria. Coherencia entre lo que se ofrece y lo que se puede agendar.
        $this->assertSame(
            '2025-01-07T13:00:00+00:00',
            $response->json('data.availability.free_slots.0.starts_at'),
        );
    }

    #[Test]
    public function test_todo_hueco_ofrecido_es_agendable(): void
    {
        // El calendario y la politica de reserva comparten reloj de pared. Esta
        // prueba es la que detectaria una divergencia entre ambos: si el
        // calendario ofreciera un hueco que la reserva rechaza, fallaria aqui.
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        $response = $this->withHeaders($this->apiKeyHeaders())
            ->getJson("/api/v1/doctors/{$doctorId}/availability?date=".self::HOY)
            ->assertOk();

        $libres = (array) $response->json('data.availability.free_slots');
        self::assertNotEmpty($libres);

        foreach ($libres as $slot) {
            $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'starts_at' => $slot['starts_at'],
                'ends_at' => $slot['ends_at'],
            ])->assertCreated();
        }
    }

    #[Test]
    public function test_limita_el_numero_de_citas_diarias_del_medico(): void
    {
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        // El techo por defecto en pruebas es de 24 citas por dia. Con citas de 10
        // minutos (el minimo admitido) caben 60 en la jornada, asi que se pueden
        // llenar las 24 y comprobar que la 25ma se rechaza.
        for ($i = 0; $i < 24; $i++) {
            $start = CarbonImmutable::parse(self::HOY.'T13:00:00Z')->addMinutes($i * 10);

            $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $start->addMinutes(10)->toIso8601String(),
            ])->assertCreated();
        }

        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'starts_at' => self::HOY.'T17:00:00Z',
            'ends_at' => self::HOY.'T17:10:00Z',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'appointment.conflict.daily_limit')
            ->assertJsonPath('error.context.max_per_day', 24);
    }

    /**
     * @return array{0: int, 1: int} [patient_id, doctor_id]
     */
    private function crearPacienteYMedico(
        string $documento = 'CC1002003004',
        string $matricula = 'MED-1001',
    ): array {
        $paciente = $this->patientService()->create(new CreatePatientData(
            fullName: 'Ana Maria Torres Rueda',
            documentId: DocumentId::from($documento),
            email: Email::from('ana'.strtolower(substr($documento, -4)).'@correo.example'),
        ));

        $medico = $this->doctorService()->create(new CreateDoctorData(
            fullName: 'Dra. Elena Vargas Ruiz',
            licenseNumber: LicenseNumber::from($matricula),
            specialty: 'Medicina General',
            email: Email::from('elena'.strtolower(substr($matricula, -4)).'@clinicapp.local'),
            workingDayStartHour: 8,
            workingDayEndHour: 18,
            slotDurationMinutes: 30,
        ));

        return [(int) $paciente->id(), (int) $medico->id()];
    }

    /**
     * Se referencia el servicio para dejar constancia de que la prueba usa el
     * caso de uso (y no SQL directo) para montar el escenario, y para fijar el
     * reloj en el instante que hace determinista toda la suite.
     */
    #[Test]
    public function test_el_reloj_de_la_aplicacion_esta_congelado(): void
    {
        self::assertSame(
            '2025-01-07T10:00:00+00:00',
            CarbonImmutable::instance($this->clock->now())->toIso8601String(),
            'El reloj congelado es lo que hace determinista esta suite.',
        );

        // Y el caso de uso lo usa de verdad.
        [$patientId, $doctorId] = $this->crearPacienteYMedico();

        $cita = $this->appointmentService()->book(new BookAppointmentData(
            patientId: $patientId,
            doctorId: $doctorId,
            timeRange: TimeRange::from(self::HOY.'T14:00:00Z', self::HOY.'T14:30:00Z'),
        ));

        self::assertSame(
            '2025-01-07T10:00:00+00:00',
            $cita->createdAt()->toIso8601String(),
        );
    }
}
