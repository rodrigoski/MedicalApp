<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Infrastructure\Persistence\Models\PatientModel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PRUEBAS DEL CRUD DE PACIENTES.
 *
 * Cubren el ciclo completo (alta, consulta, actualizacion, borrado logico) y,
 * sobre todo, dos reglas que se verifican en la base de datos y no solo en PHP:
 *   - el documento es unico,
 *   - el borrado es LOGICO y el registro conserva su historia.
 */
final class PatientApiTest extends TestCase
{
    #[Test]
    public function test_crea_un_paciente_y_devuelve_201(): void
    {
        $response = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/patients', [
            'full_name' => '  ana   maria   torres  ',
            'document_id' => 'CC1002003004',
            'email' => 'ana.torres@correo.example',
            'phone' => '+573001234567',
            'birth_date' => '1988-04-12',
            'gender' => 'f',
            'allergies' => ['  Penicilina ', 'penicilina', 'Látex'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.patient.full_name', 'Ana Maria Torres');
        $response->assertJsonPath('data.patient.document_id', 'CC1002003004');
        $response->assertJsonPath('data.patient.gender', 'F');
        $response->assertJsonPath('data.patient.status', 'active');
        $response->assertJsonPath('data.patient.has_allergies', true);

        // El genero llega en minuscula y el dominio lo normaliza.
        self::assertSame('F', (string) $response->json('data.patient.gender'));

        // Las alergias se normalizan y se deduplican en el dominio.
        self::assertSame(
            ['Penicilina', 'Látex'],
            (array) $response->json('data.patient.allergies'),
            'La deduplicacion de alergias es una regla de dominio, no del controlador.',
        );
    }

    #[Test]
    public function test_calcula_la_edad_con_el_reloj_de_la_aplicacion(): void
    {
        // El reloj esta congelado en 2025-01-07: laedad devuelta tiene que ser
        // la de esa fecha, no la del dia en que se ejecuta la suite.
        $response = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/patients', [
            'full_name' => 'Javier Alonso Mejia',
            'document_id' => 'CC1010101010',
            'birth_date' => '1990-06-15',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.patient.age', 34);
    }

    #[Test]
    public function test_rechaza_un_documento_duplicado(): void
    {
        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/patients', [
            'full_name' => 'Ana Maria Torres',
            'document_id' => 'CC1002003004',
        ])->assertCreated();

        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/patients', [
            'full_name' => 'Otra Persona Distinta',
            'document_id' => 'CC1002003004',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['context' => ['errors' => ['document_id']]]]);
    }

    #[Test]
    public function test_rechaza_datos_invalidos_con_422_y_detalle_por_campo(): void
    {
        $response = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/patients', [
            'full_name' => 'A',
            'document_id' => '$$',
            'email' => 'no-es-un-correo',
            'birth_date' => '2030-01-01',
            'gender' => 'X99',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed');

        $errors = (array) $response->json('error.context.errors');

        // El mensaje llega en espanol y señala el campo exacto que corregir.
        self::assertArrayHasKey('full_name', $errors);
        self::assertArrayHasKey('document_id', $errors);
        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('birth_date', $errors);
        self::assertArrayHasKey('gender', $errors);
    }

    #[Test]
    public function test_normaliza_el_documento_antes_de_validar(): void
    {
        // "cc-1002003004" y "CC1002003004" son el MISMO paciente: la
        // normalizacion ocurre antes de la validacion de unicidad.
        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/patients', [
            'full_name' => 'Ana Maria Torres',
            'document_id' => 'CC1002003004',
        ])->assertCreated();

        $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/patients', [
            'full_name' => 'Ana Maria Torres Otra Vez',
            'document_id' => 'cc-1002003004',
        ])->assertStatus(422);
    }

    #[Test]
    public function test_consulta_un_paciente_por_id(): void
    {
        $id = (int) $this->crearPaciente();

        $this->withHeaders($this->apiKeyHeaders())
            ->getJson("/api/v1/patients/{$id}")
            ->assertOk()
            ->assertJsonPath('data.patient.id', $id)
            ->assertJsonPath('data.patient.document_id', 'CC1002003004');
    }

    #[Test]
    public function test_devuelve_404_para_un_paciente_inexistente(): void
    {
        $this->withHeaders($this->apiKeyHeaders())
            ->getJson('/api/v1/patients/999999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function test_lista_paginado_con_metadatos(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->crearPaciente('CC100200300'.$i);
        }

        $response = $this->withHeaders($this->apiKeyHeaders())
            ->getJson('/api/v1/patients?per_page=2&page=1&sort_by=full_name&sort_direction=asc')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.pagination.total', 3);
        $response->assertJsonPath('meta.pagination.per_page', 2);
        $response->assertJsonPath('meta.pagination.page', 1);
        $response->assertJsonPath('meta.pagination.last_page', 2);
        $response->assertJsonPath('meta.pagination.has_more', true);
    }

    #[Test]
    public function test_busca_por_nombre_documento_o_correo(): void
    {
        $this->crearPaciente('CC1002003004', 'ana.torres@correo.example');
        $this->crearPaciente('CC1010101010', 'javier.mejia@correo.example');

        $this->withHeaders($this->apiKeyHeaders())
            ->getJson('/api/v1/patients?search=torres')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_id', 'CC1002003004');

        $this->withHeaders($this->apiKeyHeaders())
            ->getJson('/api/v1/patients?search=CC1010')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Un comodin de SQL debe buscarse como texto literal, no como patron.
        $this->withHeaders($this->apiKeyHeaders())
            ->getJson('/api/v1/patients?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function test_actualiza_un_paciente(): void
    {
        $id = (int) $this->crearPaciente();

        $this->withHeaders($this->apiKeyHeaders())
            ->patchJson("/api/v1/patients/{$id}", [
                'full_name' => 'Ana Maria Torres Rueda',
                'phone' => '+573009999999',
                'allergies' => ['Látex', 'Ibuprofeno'],
            ])
            ->assertOk()
            ->assertJsonPath('data.patient.full_name', 'Ana Maria Torres Rueda')
            ->assertJsonPath('data.patient.phone', '+573009999999');

        self::assertSame(
            ['Látex', 'Ibuprofeno'],
            (array) $this->withHeaders($this->apiKeyHeaders())
                ->getJson("/api/v1/patients/{$id}")
                ->json('data.patient.allergies'),
        );
    }

    #[Test]
    public function test_el_borrado_es_logico_y_el_registro_conserva_la_historia(): void
    {
        $id = (int) $this->crearPaciente();

        $this->withHeaders($this->apiKeyHeaders())
            ->deleteJson("/api/v1/patients/{$id}")
            ->assertOk();

        // La fila sigue existiendo: en una clinica el historial no se borra.
        self::assertNotNull(PatientModel::query()->withTrashed()->find($id));
        self::assertNotNull(PatientModel::query()->withTrashed()->find($id)?->deleted_at);

        // Pero ya no aparece en el listado ni se puede consultar.
        $this->withHeaders($this->apiKeyHeaders())->getJson("/api/v1/patients/{$id}")->assertStatus(404);
        $this->withHeaders($this->apiKeyHeaders())->getJson('/api/v1/patients')->assertJsonCount(0, 'data');
    }

    #[Test]
    public function test_las_alergias_se_pueden_borrar_dejando_el_resto_intacto(): void
    {
        $id = (int) $this->crearPaciente('CC1002003004', 'ana@correo.example', ['Penicilina']);

        $this->withHeaders($this->apiKeyHeaders())
            ->patchJson("/api/v1/patients/{$id}", ['allergies' => []])
            ->assertOk()
            ->assertJsonPath('data.patient.has_allergies', false);

        self::assertSame([], (array) $this->withHeaders($this->apiKeyHeaders())
            ->getJson("/api/v1/patients/{$id}")
            ->json('data.patient.allergies'));
    }

    private function crearPaciente(
        string $documento = 'CC1002003004',
        ?string $email = null,
        array $allergies = [],
    ): int {
        $response = $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/patients', [
            'full_name' => 'Ana Maria Torres Rueda',
            'document_id' => $documento,
            'email' => $email,
            'allergies' => $allergies,
        ]);

        $response->assertCreated();

        return (int) $response->json('data.patient.id');
    }
}
