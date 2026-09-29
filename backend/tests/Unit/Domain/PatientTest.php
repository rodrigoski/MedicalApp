<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Entities\Patient;
use App\Domain\Exceptions\InvalidDocumentId;
use App\Domain\ValueObjects\DocumentId;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PRUEBAS DEL AGREGADO PACIENTE.
 *
 * Verifican las invariantes que el constructor privado protege: ningun Patient
 * puede existir con un documento invalido, un nombre vacio o una lista de
 * alergias sucia. Esa es la ventaja de "constructor privado + factorias": el
 * objeto invalido no se puede construir, en vez de confiar en que todos los
 * llamadores recuerden validarlo.
 */
final class PatientTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2025-01-07T10:00:00Z');
    }

    #[Test]
    public function test_un_paciente_nace_activo_y_no_eliminado(): void
    {
        $patient = $this->newPatient();

        self::assertTrue($patient->isActive());
        self::assertFalse($patient->isDeleted());
        self::assertNull($patient->id(), 'El id se asigna al insertar, no antes.');
    }

    #[Test]
    public function test_normaliza_el_nombre_collapse_espacios(): void
    {
        $patient = Patient::register(
            fullName: '  ana   maria   torres  ',
            documentId: DocumentId::from('CC1002003004'),
            now: $this->now,
        );

        self::assertSame('Ana Maria Torres', $patient->fullName());
    }

    #[Test]
    public function test_desactiva_y_reactiva(): void
    {
        $patient = $this->newPatient();

        $patient->deactivate($this->now);
        self::assertFalse($patient->isActive());

        $patient->activate($this->now);
        self::assertTrue($patient->isActive());
    }

    #[Test]
    public function test_el_borrado_logico_invalida_el_paciente(): void
    {
        $patient = $this->newPatient();
        $patient->markAsDeleted();

        self::assertTrue($patient->isDeleted());
        self::assertFalse($patient->isActive(), 'Un paciente borrado no admite nuevas citas.');
    }

    #[Test]
    public function test_no_se_puede_asignar_dos_identificadores(): void
    {
        $patient = $this->newPatient();
        $patient->assignId(7);

        $this->expectException(\LogicException::class);

        $patient->assignId(8);
    }

    #[Test]
    public function test_normaliza_las_alergias_y_elimina_duplicados(): void
    {
        $patient = $this->newPatient();

        $patient->changeAllergies([
            '  Penicilina ',
            'penicilina',
            'Látex',
            '',
            '   ',
        ]);

        self::assertSame(['Penicilina', 'Látex'], $patient->allergies());
        self::assertTrue($patient->hasAllergies());
    }

    #[Test]
    public function test_las_alergias_nacen_vacias(): void
    {
        $patient = $this->newPatient();

        self::assertSame([], $patient->allergies());
        self::assertFalse($patient->hasAllergies());
    }

    #[Test]
    public function test_se_pueden_borrar_todas_las_alergias(): void
    {
        $patient = Patient::register(
            fullName: 'Ana Torres',
            documentId: DocumentId::from('CC1002003004'),
            allergies: ['Penicilina'],
            now: $this->now,
        );

        $patient->changeAllergies([]);

        self::assertSame([], $patient->allergies());
    }

    #[Test]
    public function test_el_constructor_rechaza_documentos_invalidos(): void
    {
        $this->expectException(InvalidDocumentId::class);

        Patient::register(
            fullName: 'Ana Torres',
            documentId: DocumentId::from('AB'),
            now: $this->now,
        );
    }

    #[Test]
    public function test_calcula_la_edad_en_anios(): void
    {
        $patient = Patient::register(
            fullName: 'Ana Torres',
            documentId: DocumentId::from('CC1002003004'),
            birthDate: CarbonImmutable::parse('1990-06-15', 'UTC')->startOfDay(),
            now: $this->now,
        );

        // 1990-06-15 -> 2025-01-07: 34 anios cumplidos.
        // Se pasa el instante de referencia para no depender del reloj real.
        self::assertSame(34, $patient->ageInYears($this->now));
    }

    #[Test]
    public function test_la_edad_es_null_sin_fecha_de_nacimiento(): void
    {
        self::assertNull($this->newPatient()->ageInYears());
    }

    #[Test]
    public function test_el_genero_se_normaliza_a_mayuscula(): void
    {
        $patient = Patient::register(
            fullName: 'Ana Torres',
            documentId: DocumentId::from('CC1002003004'),
            gender: 'f',
            now: $this->now,
        );

        self::assertSame('F', $patient->gender());
    }

    private function newPatient(): Patient
    {
        return Patient::register(
            fullName: 'Ana Torres',
            documentId: DocumentId::from('CC1002003004'),
            now: $this->now,
        );
    }
}
