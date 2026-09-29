<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Support\ApiKeys;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PRUEBAS DE SEGURIDAD DE LA CAPA HTTP.
 *
 * Criterio de auditoria cubierto: "la API exige credencial en las operaciones
 * que modifican datos". Ademas se fija el CONTRATO de los errores de
 * autenticacion (codigo estable), porque un cliente que depende de el no debe
 * romperse porque el mensaje haya cambiado de redaccion.
 */
final class ApiSecurityTest extends TestCase
{
    #[Test]
    public function test_rechaza_lecturas_sin_api_key(): void
    {
        $this->getJson('/api/v1/patients')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'authentication.api_key_missing')
            ->assertJsonPath('error.status', 401);
    }

    #[Test]
    public function test_rechaza_lecturas_con_api_key_invalida(): void
    {
        $this->withHeader('X-Api-Key', 'clave-inventada')
            ->getJson('/api/v1/patients')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'authentication.api_key_invalid');
    }

    #[Test]
    public function test_rechaza_escrituras_sin_api_key(): void
    {
        $this->postJson('/api/v1/patients', [
            'full_name' => 'Ana Torres',
            'document_id' => 'CC1002003004',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'authentication.api_key_missing');
    }

    #[Test]
    public function test_el_health_check_es_publico_para_que_lo_use_el_orquestador(): void
    {
        // El healthcheck de Docker y el upstream de Nginx no pueden adjuntar
        // credenciales: por eso esta ruta es publica y devuelve el minimo.
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.checks.database.status', 'ok');
    }

    #[Test]
    public function test_health_no_expone_detalles_de_la_base_de_datos(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        // El nombre de la base, el host, el usuario y la contrasena no deben
        // viajar en la respuesta: es informacion util para un atacante.
        $body = (string) $response->getContent();

        self::assertStringNotContainsString('password', strtolower($body));
        self::assertStringNotContainsString('pgsql', strtolower($body));
        self::assertStringNotContainsString('DB_PASSWORD', $body);

        // Cuando la comprobacion es correcta, tampoco hace falta un mensaje.
        self::assertArrayNotHasKey('message', (array) $response->json('data.checks.database'));
    }

    #[Test]
    public function test_acepta_rotacion_de_claves_varias_a_la_vez(): void
    {
        // Varias claves separadas por coma permiten rotar sin caida: se agrega
        // la nueva, se despliega, y cuando todos usan la nueva se retira la vieja.
        config(['app.internal_api_keys' => 'clave-nueva, test-clinic-key']);
        ApiKeys::flush();

        $this->withHeader('X-Api-Key', 'test-clinic-key')
            ->getJson('/api/v1/patients')
            ->assertOk();

        $this->withHeader('X-Api-Key', 'clave-nueva')
            ->getJson('/api/v1/patients')
            ->assertOk();
    }

    #[Test]
    public function test_sin_claves_configuradas_se_rechaza_todo(): void
    {
        // Fallar cerrado: un despliegue mal configurado no debe abrir la puerta.
        config(['app.internal_api_keys' => '']);
        ApiKeys::flush();

        $this->withHeader('X-Api-Key', 'lo-que-sea')
            ->getJson('/api/v1/patients')
            ->assertStatus(401);
    }

    #[Test]
    public function test_agrega_cabeceras_de_seguridad_en_toda_respuesta(): void
    {
        $response = $this->withHeaders($this->apiKeyHeaders())->getJson('/api/v1/patients');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Request-Id');
        $response->assertHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    #[Test]
    public function test_el_log_no_expone_datos_clinicos(): void
    {
        // Se apunta el canal por defecto a un archivo de prueba para poder
        // inspeccionar EXACTAMENTE lo que se escribiria en produccion.
        $logPath = storage_path('logs/audit-prueba.log');

        @unlink($logPath);

        config([
            'logging.default' => 'audit',
            'logging.channels.audit' => [
                'driver' => 'single',
                'path' => $logPath,
                'level' => 'debug',
            ],
        ]);

        try {
            // La peticion se acepta (201) pero su cuerpo jamas debe quedar
            // registrado: el log lleva metadatos, no historia clinica.
            $this->withHeaders($this->apiKeyHeaders())->postJson('/api/v1/patients', [
                'full_name' => 'Paciente Con Alergia Severa',
                'document_id' => 'CC1002003004',
                'allergies' => ['Penicilina'],
            ])->assertCreated();

            $this->assertFileExists($logPath, 'La peticion debe registrarse en el log.');

            $log = (string) file_get_contents($logPath);

            // El log tiene que registrar la peticion...
            self::assertStringContainsString('http.request', $log);
            self::assertStringContainsString('request_id', $log);

            // ...pero no sus datos clinicos ni la credencial.
            self::assertStringNotContainsString('Penicilina', $log);
            self::assertStringNotContainsString('CC1002003004', $log);
            self::assertStringNotContainsString('test-clinic-key', $log);
        } finally {
            @unlink($logPath);
        }
    }

    #[Test]
    public function test_un_endpoint_inexistente_devuelve_404_json_y_no_html(): void
    {
        $this->withHeaders($this->apiKeyHeaders())
            ->getJson('/api/v1/no-existe')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'route.not_found');
    }

    #[Test]
    public function test_toda_respuesta_lleva_request_id_en_el_cuerpo_y_en_la_cabecera(): void
    {
        // El identificador tiene que estar en los DOS sitios. En la cabecera
        // para quien solo inspecciona la red, y en el cuerpo para quien reporta
        // un fallo copiando el JSON de la respuesta.
        $response = $this->withHeaders($this->apiKeyHeaders())->getJson('/api/v1/patients');

        $response->assertOk();

        $fromHeader = $response->headers->get('X-Request-Id');

        self::assertNotNull($fromHeader);
        self::assertNotSame('', $fromHeader);
        self::assertSame($fromHeader, $response->json('request_id'));
    }

    #[Test]
    public function test_reutiliza_el_request_id_que_envia_el_cliente(): void
    {
        // Nginx genera un identificador por peticion y lo reenvia como
        // `X-Request-Id`. Si el backend lo ignorara y generara otro, el log del
        // gateway y el log de la aplicacion no se podrian correlacionar, que es
        // justo para lo que existe.
        $this->withHeaders([...$this->apiKeyHeaders(), 'X-Request-Id' => 'req-del-gateway-001'])
            ->getJson('/api/v1/patients')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'req-del-gateway-001')
            ->assertJsonPath('request_id', 'req-del-gateway-001');
    }

    #[Test]
    public function test_ignora_un_request_id_con_caracteres_de_control(): void
    {
        // Una cabecera la controla el cliente, y su valor acaba en un log y en
        // una cabecera de respuesta. Si se aceptara sin filtrar, un salto de
        // linea permitiria falsificar lineas de log.
        $malicioso = "req-1\nGET /admin 200 - forjado";

        $response = $this->withHeaders([...$this->apiKeyHeaders(), 'X-Request-Id' => $malicioso])
            ->getJson('/api/v1/patients');

        $response->assertOk();

        $emitido = (string) $response->headers->get('X-Request-Id');

        self::assertStringNotContainsString("\n", $emitido);
        self::assertStringNotContainsString('/admin', $emitido);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._\-]+$/', $emitido);
    }

    #[Test]
    public function test_el_rechazo_de_credencial_tambien_lleva_request_id(): void
    {
        // Un 401 es el evento que mas necesita identificador: no hay cuerpo de
        // peticion que inspeccionar, solo la linea de log. Y para llegar a el
        // hay que haberlo generado ANTES del guardian de API key.
        $response = $this->getJson('/api/v1/patients');

        $response->assertStatus(401);

        self::assertNotNull($response->headers->get('X-Request-Id'));
        self::assertSame($response->headers->get('X-Request-Id'), $response->json('request_id'));
    }

    #[Test]
    public function test_el_limite_de_tasa_devuelve_429_con_codigo_estable_y_retry_after(): void
    {
        // Se REDEFINE el limite en vez de disparar las 121 peticiones que
        // permitiria el limite real. Lo que se fija aqui es el CONTRATO del
        // error, no el rendimiento del limitador, que ya cubre el framework.
        RateLimiter::for('reads', static fn (): Limit => Limit::perMinute(1));

        $this->withHeaders($this->apiKeyHeaders())->getJson('/api/v1/patients')->assertOk();
        $this->withHeaders($this->apiKeyHeaders())->getJson('/api/v1/patients')->assertOk();

        // El 429 es de los pocos errores que un cliente trata de forma
        // automatica, asi que necesita tres cosas: un `code` con el que
        // ramificar, el status correcto, y `Retry-After` para saber cuando
        // reintentar en lugar de adivinarlo.
        $this->withHeaders($this->apiKeyHeaders())
            ->getJson('/api/v1/patients')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limit.exceeded')
            ->assertJsonPath('error.status', 429)
            ->assertHeader('Retry-After');
    }

    #[Test]
    public function test_el_health_publico_tambien_lleva_request_id(): void
    {
        // Docker y Nginx consultan esta ruta sin credencial; sin identificador,
        // un fallo de arranque no se podria correlacionar con nada.
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();

        $requestId = $response->json('request_id');

        self::assertIsString($requestId);
        self::assertNotSame('', $requestId);
        self::assertSame($requestId, $response->headers->get('X-Request-Id'));
    }
}
