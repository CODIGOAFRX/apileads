<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Domain\Import\ColumnMapper;
use Leads\Domain\Import\ImportField as F;
use Leads\Domain\Import\Table;
use PHPUnit\Framework\TestCase;

final class ColumnMapperTest extends TestCase
{
    private ColumnMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ColumnMapper();
    }

    public function testReconoceUnaExportacionDeFormularioWeb(): void
    {
        $mapping = $this->mapper->map(new Table([
            ['Nombre', 'Apellidos', 'E-mail', 'Teléfono móvil', 'Ciudad', 'Curso de interés', 'Mensaje', 'Fecha'],
            ['Lucía', 'Martín', 'lucia@example.com', '600123454', 'Granada', 'Grado Diseño Gráfico', 'Hola', '15/09/2026'],
        ]));

        self::assertSame(
            [F::NOMBRE, F::APELLIDOS, F::EMAIL, F::TELEFONO, F::UBICACION, F::ESTUDIO, F::COMENTARIOS, F::FECHA],
            $mapping->fields
        );
        self::assertSame(0, $mapping->headerRow);
        self::assertFalse($mapping->looksLikeFacebook);
    }

    public function testReconoceFacebookLeadAds(): void
    {
        $mapping = $this->mapper->map(new Table([
            ['id', 'created_time', 'ad_id', 'ad_name', 'adset_name', 'campaign_name', 'form_name', 'is_organic', 'platform', '¿qué_estudios_te_interesan?', '¿en_qué_ciudad_vives?', 'full_name', 'email', 'phone_number'],
            ['l:123', '2026-09-15T10:23:11+0200', 'ag:1', 'Anuncio A', 'Conjunto', 'Captación 26', 'Form', 'false', 'ig', 'máster_ilustración', 'Jaén', 'Pablo Ruiz', 'pablo@example.com', 'p:+34611222333'],
        ]));
        $f = $mapping->fields;

        self::assertTrue($mapping->looksLikeFacebook);
        self::assertSame(F::FECHA, $f[1]);
        self::assertSame(F::CAMPANA, $f[5], 'campaign_name es la columna de campaña preferida');
        self::assertSame(F::EXTRA, $f[3], 'las demás columnas de campaña se guardan como extra');
        self::assertSame(F::EXTRA, $f[6]);
        self::assertSame(F::ORIGEN, $f[8]);
        self::assertSame(F::ESTUDIO, $f[9]);
        self::assertSame(F::UBICACION, $f[10]);
        self::assertSame(F::NOMBRE_COMPLETO, $f[11]);
        self::assertSame(F::EMAIL, $f[12]);
        self::assertSame(F::TELEFONO, $f[13]);
        self::assertSame(F::EXTRA, $f[0]);
    }

    public function testUnaPreguntaDeConsentimientoNoSeConfundeConElCorreo(): void
    {
        $mapping = $this->mapper->map(new Table([
            ['Nombre', '¿Aceptas recibir información por email?', 'Correo electrónico'],
            ['Ana', 'Sí', 'ana@example.com'],
            ['Luis', 'No', 'luis@example.com'],
        ]));

        self::assertSame([F::NOMBRE, F::EXTRA, F::EMAIL], $mapping->fields);
    }

    public function testDetectaCabecerasDesconocidasPorContenido(): void
    {
        $mapping = $this->mapper->map(new Table([
            ['Persona', 'Contacto 1', 'Contacto 2'],
            ['Eva', 'eva@example.com', '612 345 678'],
            ['Juan', 'juan@example.com', '+34 699 000 111'],
        ]));

        self::assertSame(F::EMAIL, $mapping->fields[1]);
        self::assertSame(F::TELEFONO, $mapping->fields[2]);
    }

    public function testFicheroSinCabecera(): void
    {
        $mapping = $this->mapper->map(new Table([
            ['Eva López', 'eva@example.com', '612345678'],
            ['Juan Pérez', 'juan@example.com', '699000111'],
        ]));

        self::assertNull($mapping->headerRow);
        self::assertSame([F::NOMBRE_COMPLETO, F::EMAIL, F::TELEFONO], $mapping->fields);
        self::assertCount(2, $mapping->dataRows, 'la primera fila también es un lead');
    }

    public function testIgnoraFilasDeTituloAntesDeLaCabecera(): void
    {
        $mapping = $this->mapper->map(new Table([
            ['Leads jornada de puertas abiertas', '', ''],
            ['', '', ''],
            ['Nombre', 'Email', 'Teléfono'],
            ['Eva', 'eva@example.com', '612345678'],
            ['', '', ''],
            ['Juan', 'juan@example.com', '699000111'],
        ]));

        self::assertSame(2, $mapping->headerRow);
        self::assertSame([3, 5], array_keys($mapping->dataRows), 'las claves son la posición en el fichero');
    }

    public function testLosAjustesManualesLiberanLaColumnaAnterior(): void
    {
        $mapping = $this->mapper->map(new Table([
            ['Email', 'Email alternativo'],
            ['a@example.com', 'b@example.com'],
        ]))->withOverrides(['1' => 'email']);

        self::assertSame([F::EXTRA, F::EMAIL], $mapping->fields);
    }
}
