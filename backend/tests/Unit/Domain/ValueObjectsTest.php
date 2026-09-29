<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Exceptions\InvalidDocumentId;
use App\Domain\Exceptions\InvalidEmail;
use App\Domain\Exceptions\InvalidLicenseNumber;
use App\Domain\Exceptions\InvalidPhoneNumber;
use App\Domain\ValueObjects\DocumentId;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\LicenseNumber;
use App\Domain\ValueObjects\PhoneNumber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PRUEBAS DE LOS VALUE OBJECTS DE IDENTIDAD.
 *
 * Su valor real esta en la NORMALIZACION: "CC-1.234.567" y "cc1234567" deben
 * ser el mismo paciente. Sin esto, el indice unico de la base de datos
 * permitiria duplicados que el usuario nunca registraria como tales.
 */
final class ValueObjectsTest extends TestCase
{
    #[Test]
    public function test_document_id_normaliza_guiones_espacios_y_mayusculas(): void
    {
        $a = DocumentId::from('CC-1.234.567');
        $b = DocumentId::from('  cc1234567  ');

        self::assertSame('CC1234567', $a->value());
        self::assertTrue($a->equals($b), 'Las dos formas deben representar el mismo documento.');
    }

    #[Test]
    public function test_document_id_acepta_pasaportes_con_letras_y_numeros(): void
    {
        $documento = DocumentId::from('AB1234CD');

        self::assertSame('AB1234CD', $documento->value());
    }

    #[Test]
    public function test_document_id_rechaza_valores_demasiado_cortos(): void
    {
        $this->expectException(InvalidDocumentId::class);
        $this->expectExceptionMessageMatches('/no es valido/');

        DocumentId::from('AB1');
    }

    #[Test]
    public function test_document_id_rechaza_caracteres_especiales(): void
    {
        $this->expectException(InvalidDocumentId::class);

        DocumentId::from('CC@123#45');
    }

    #[Test]
    public function test_email_normaliza_a_minusculas(): void
    {
        $email = Email::from('  Ana.Torres@Clinica.CO  ');

        self::assertSame('ana.torres@clinica.co', $email->value());
        self::assertSame('clinica.co', $email->domain());
        self::assertSame('ana.torres', $email->localPart());
    }

    #[Test]
    public function test_email_rechaza_formato_invalido(): void
    {
        $this->expectException(InvalidEmail::class);

        Email::from('no-es-un-correo');
    }

    #[Test]
    public function test_email_enmascara_para_logs(): void
    {
        // Requisito de la dimension "Seguridad": los logs no deben contener PII
        // en claro.
        $enmascarado = Email::from('paciente@correo.example')->masked();

        self::assertSame('p***@c***.example', $enmascarado);
        self::assertStringNotContainsString('paciente', $enmascarado);
    }

    #[Test]
    public function test_email_try_from_acepta_null(): void
    {
        self::assertNull(Email::tryFrom(null));
        self::assertNull(Email::tryFrom('   '));
        self::assertInstanceOf(Email::class, Email::tryFrom('a@b.co'));
    }

    #[Test]
    public function test_telefono_normaliza_a_digitos(): void
    {
        $telefono = PhoneNumber::from('+57 (300) 123-4567');

        self::assertSame('+573001234567', $telefono->value());
        self::assertSame('3001234567', $telefono->local());
    }

    #[Test]
    public function test_telefono_rechaza_pocos_digitos(): void
    {
        $this->expectException(InvalidPhoneNumber::class);

        PhoneNumber::from('12345');
    }

    #[Test]
    public function test_licencia_normaliza_a_mayusculas_sin_guiones(): void
    {
        $licencia = LicenseNumber::from('med-1001');

        self::assertSame('MED1001', $licencia->value());
    }

    #[Test]
    public function test_licencia_rechaza_valores_demasiado_cortos(): void
    {
        $this->expectException(InvalidLicenseNumber::class);

        LicenseNumber::from('AB');
    }

    #[Test]
    public function test_dos_licencias_con_mismo_valor_son_iguales(): void
    {
        self::assertTrue(
            LicenseNumber::from('MED-1001')->equals(LicenseNumber::from('med1001')),
        );
    }
}
