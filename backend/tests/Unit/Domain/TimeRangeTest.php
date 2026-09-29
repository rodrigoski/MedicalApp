<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exceptions\InvalidTimeRange;
use App\Domain\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PRUEBAS UNITARIAS DE TimeRange.
 *
 * Son las pruebas mas importantes del proyecto porque TimeRange CODIFICA LA
 * DEFINICION DE TRASLAPE. Si esta clase esta mal, toda la regla de negocio
 * esta mal, y ninguna prueba de integracion lo detectaria con tanta precision.
 *
 * No usan base de datos ni Laravel: corren en milisegundos y localizan el
 * fallo exactamente en la linea culpable.
 */
final class TimeRangeTest extends TestCase
{
    /**
     * Matriz de traslapes. Cada caso tiene un nombre legible porque, cuando
     * una prueba falla, el nombre es la primera informacion que se lee.
     *
     * La regla verificada es:  traslape <=> inicioA < finB  &&  inicioB < finA
     *
     * @return array<string, array{start: string, end: string, expected: bool}>
     */
    public static function overlapCases(): array
    {
        return [
            'caso 1: rangos identicos se traslapan' => [
                'start' => '10:00', 'end' => '11:00', 'expected' => true,
            ],
            'caso 2: B contenido dentro de A' => [
                'start' => '10:00', 'end' => '12:00', 'expected' => true,
            ],
            'caso 3: A empieza y termina antes que B' => [
                'start' => '10:00', 'end' => '10:30', 'expected' => false,
            ],
            'caso 4: B empieza y termina antes que A' => [
                'start' => '08:00', 'end' => '09:00', 'expected' => false,
            ],
            'caso 5: solapamiento parcial por la izquierda' => [
                'start' => '09:00', 'end' => '10:30', 'expected' => true,
            ],
            'caso 6: solapamiento parcial por la derecha' => [
                'start' => '10:30', 'end' => '12:00', 'expected' => true,
            ],
            'caso 7: consecutivo por la derecha (se tocan, no se traslapan)' => [
                'start' => '11:00', 'end' => '12:00', 'expected' => false,
            ],
            'caso 8: consecutivo por la izquierda (se tocan, no se traslapan)' => [
                'start' => '09:00', 'end' => '10:00', 'expected' => false,
            ],
            'caso 9: un solo minuto de solapamiento' => [
                'start' => '10:59', 'end' => '11:30', 'expected' => true,
            ],
            'caso 10: un solo minuto de holgura' => [
                'start' => '11:01', 'end' => '12:00', 'expected' => false,
            ],
            'caso 11: un minuto exacto de solapamiento' => [
                'start' => '10:00', 'end' => '11:01', 'expected' => true,
            ],
            'caso 12: un minuto exacto de holgura' => [
                'start' => '10:01', 'end' => '11:00', 'expected' => false,
            ],
            'caso 13: cubre toda la jornada' => [
                'start' => '08:00', 'end' => '18:00', 'expected' => true,
            ],
            'caso 14: termina justo al iniciar la otra' => [
                'start' => '07:30', 'end' => '10:00', 'expected' => false,
            ],
            'caso 15: empieza justo al terminar la otra' => [
                'start' => '11:00', 'end' => '12:30', 'expected' => false,
            ],
        ];
    }

    /**
     * @param bool $expected
     */
    #[Test]
    #[DataProvider('overlapCases')]
    public function test_detecta_traslape_correctamente(string $start, string $end, bool $expected): void
    {
        // Referencia fija: 10:00 - 11:00 UTC del 7 de enero de 2025.
        $reference = self::range('10:00', '11:00');
        $other = self::range($start, $end);

        self::assertSame(
            $expected,
            $reference->overlaps($other),
            sprintf('Fallo: 10:00-11:00 vs %s-%s', $start, $end),
        );

        // La simetria es parte del contrato: el traslape no depende del orden.
        self::assertSame(
            $expected,
            $other->overlaps($reference),
            sprintf('Fallo simetrico: %s-%s vs 10:00-11:00', $start, $end),
        );
    }

    #[Test]
    public function test_rechaza_intervalo_con_fin_anterior_al_inicio(): void
    {
        $this->expectException(InvalidTimeRange::class);
        $this->expectExceptionMessage('La hora de fin de la cita debe ser posterior a la hora de inicio.');

        TimeRange::from('2025-01-07T10:00:00Z', '2025-01-07T09:00:00Z');
    }

    #[Test]
    public function test_rechaza_intervalo_de_duracion_cero(): void
    {
        $this->expectException(InvalidTimeRange::class);

        TimeRange::from('2025-01-07T10:00:00Z', '2025-01-07T10:00:00Z');
    }

    #[Test]
    public function test_rechaza_duracion_excesiva(): void
    {
        $this->expectException(InvalidTimeRange::class);
        $this->expectExceptionMessageMatches('/duracion de la cita debe estar entre/');

        TimeRange::forAppointment('2025-01-07T08:00:00Z', '2025-01-07T18:00:00Z');
    }

    #[Test]
    public function test_rechaza_duracion_muy_corta(): void
    {
        $this->expectException(InvalidTimeRange::class);

        TimeRange::forAppointment('2025-01-07T10:00:00Z', '2025-01-07T10:02:00Z');
    }

    #[Test]
    public function test_acepta_duracion_en_los_limites(): void
    {
        $minimo = TimeRange::forAppointment('2025-01-07T10:00:00Z', '2025-01-07T10:10:00Z');
        self::assertSame(TimeRange::MIN_DURATION_MINUTES, $minimo->durationInMinutes());

        $maximo = TimeRange::forAppointment('2025-01-07T10:00:00Z', '2025-01-07T18:00:00Z');
        self::assertSame(TimeRange::MAX_DURATION_MINUTES, $maximo->durationInMinutes());
    }

    #[Test]
    public function test_normaliza_a_utc(): void
    {
        // 05:00 en Bogotá (UTC-5) equivale a 10:00 UTC. El sistema compara
        // siempre en UTC, por eso la conversion ocurre al construir el objeto.
        $range = TimeRange::from('2025-01-07T05:00:00-05:00', '2025-01-07T06:00:00-05:00');

        self::assertSame('10:00', $range->startAt()->format('H:i'));
        self::assertSame('11:00', $range->endAt()->format('H:i'));
        self::assertSame('UTC', $range->startAt()->timezoneName);
    }

    #[Test]
    public function test_expone_la_hora_local_sin_cambiar_el_almacenado(): void
    {
        $range = TimeRange::from('2025-01-07T14:00:00Z', '2025-01-07T14:30:00Z');

        self::assertSame('09:00', $range->startsAtLocal('America/Bogota')->format('H:i'));
        self::assertSame('14:00', $range->startAt()->format('H:i'), 'El valor interno sigue en UTC.');
    }

    #[Test]
    public function test_es_inmutable_al_desplazar(): void
    {
        $original = self::range('10:00', '11:00');
        $shifted = $original->shiftByMinutes(60);

        self::assertSame('10:00', $original->startAt()->format('H:i'), 'El original no debe mutar.');
        self::assertSame('11:00', $shifted->startAt()->format('H:i'));
        self::assertSame('12:00', $shifted->endAt()->format('H:i'));
    }

    #[Test]
    public function test_overlaps_any_devuelve_true_si_alguno_traslapa(): void
    {
        $candidate = self::range('10:00', '10:30');

        $ranges = [
            self::range('08:00', '09:00'),
            self::range('10:15', '10:45'),
        ];

        self::assertTrue($candidate->overlapsAny($ranges));
        self::assertCount(1, $candidate->conflictsWith($ranges));
    }

    #[Test]
    public function test_overlaps_any_devuelve_false_si_ninguno_traslapa(): void
    {
        $candidate = self::range('10:00', '10:30');

        self::assertFalse($candidate->overlapsAny([
            self::range('08:00', '09:00'),
            self::range('10:30', '11:00'),
        ]));
    }

    #[Test]
    public function test_to_array_expone_iso8601(): void
    {
        $array = self::range('10:00', '11:00')->toArray();

        self::assertSame('2025-01-07T10:00:00+00:00', $array['starts_at']);
        self::assertSame('2025-01-07T11:00:00+00:00', $array['ends_at']);
    }

    private static function range(string $start, string $end): TimeRange
    {
        return TimeRange::from(
            CarbonImmutable::parse('2025-01-07T'.$start.':00Z'),
            CarbonImmutable::parse('2025-01-07T'.$end.':00Z'),
        );
    }
}
