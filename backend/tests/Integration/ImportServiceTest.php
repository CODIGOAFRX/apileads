<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

use Leads\Domain\Import\Table;
use Leads\Infrastructure\Spreadsheet\SpreadsheetReader;

final class ImportServiceTest extends DatabaseTestCase
{
    private const CSV = <<<CSV
    Nombre;Apellidos;Email;Teléfono;Ciudad;Curso de interés;Origen;Mensaje;Fecha
    LUCÍA;martín;lucia@example.com;600 12 34 54;Granada;Grado Diseño Gráfico;Web;Becas;15/09/2026 09:12
    Pablo;;pablo@example.com;;Jaén;Ilustración;Instagram;;
    ;;;;Sevilla;;;;
    Marta;;;611222333;;Diseño gráfico, Máster moda;;;fecha rara
    Lucía;Martín Pérez;;+34 600123454;;Máster ilustración;;Repite;
    Correo;Malo;correo-malo@;622333444;;Informática;;;
    CSV;

    private function table(string $csv = self::CSV): Table
    {
        return (new SpreadsheetReader())->readCsvString($csv);
    }

    public function testPrevisualizaSinGuardarNada(): void
    {
        $preview = $this->app->imports()->preview($this->table(), []);

        self::assertSame(6, $preview['totalFilas']);
        self::assertSame(5, $preview['importables']);
        self::assertSame(1, $preview['sinContacto']);
        self::assertSame(1, $preview['repetidosEnFichero']);
        self::assertSame(0, $preview['yaExisten']);
        self::assertSame([], $preview['faltan']);
        self::assertSame('OTRO', $preview['origenDetectado']);
        self::assertSame(['ilustracion', 'diseno grafico', 'informatica'], array_column($preview['estudiosSinReconocer'], 'clave'));
        self::assertSame(2, $preview['muestra'][0]['fila']);
        self::assertSame('Lucía Martín', $preview['muestra'][0]['nombre']);
        self::assertSame([2], $preview['muestra'][0]['estudioIds']);
        self::assertSame('WEB', $preview['muestra'][0]['origen']);
        self::assertSame('2026-09-15 09:12', $preview['muestra'][0]['fecha']);
        self::assertSame('email', $preview['columnas'][2]['campo']);
        self::assertSame(0, $this->app->leads()->counters()['todos']);
    }

    public function testImportaFusionaYAprendeLosEstudiosElegidos(): void
    {
        $report = $this->app->imports()->import($this->table(), 'leads.csv', [
            'origen' => 'FERIA',
            'estudios' => ['ilustracion' => 4, 'diseno grafico' => 2, 'informatica' => 0, 'inventado' => 999],
        ], (int) $this->comercial['id']);

        self::assertSame(4, $report['creados']);
        self::assertSame(1, $report['fusionados']);
        self::assertSame(1, $report['omitidos']);
        self::assertSame(['fila' => 4, 'tipo' => 'omitida', 'mensaje' => 'Fila sin nombre, correo ni teléfono'], $report['incidencias'][0]);
        self::assertStringContainsString('Fecha no reconocida', $report['incidencias'][1]['mensaje']);
        self::assertStringContainsString('Correo con formato dudoso', $report['incidencias'][2]['mensaje']);

        $leads = $this->app->leads()->search(['bandeja' => 'todos', 'porPagina' => 50], $this->comercial)['items'];
        $byEmail = array_column($leads, null, 'email');

        $lucia = $byEmail['lucia@example.com'];
        self::assertSame('Lucía', $lucia['nombre']);
        self::assertSame('Martín', $lucia['apellidos']);
        self::assertSame('+34 600 12 34 54', $lucia['telefono']);
        self::assertSame([2, 5], array_column($lucia['estudios'], 'id'), 'la fila repetida añade el máster');
        self::assertStringContainsString('Repite', $lucia['comentarios']);
        self::assertSame('WEB', $lucia['origen'], 'el origen de la columna manda sobre el elegido');
        self::assertSame('2026-09-15T07:12:00Z', $lucia['fechaOrigen']);

        self::assertSame('REDES_SOCIALES', $byEmail['pablo@example.com']['origen']);
        self::assertSame([4], array_column($byEmail['pablo@example.com']['estudios'], 'id'));

        $marta = array_values(array_filter($leads, static fn ($l) => $l['nombre'] === 'Marta'))[0];
        self::assertSame('FERIA', $marta['origen']);
        self::assertSame([2, 6], array_column($marta['estudios'], 'id'));
        self::assertSame(['Fecha original' => 'fecha rara'], $marta['extra']);

        $malo = $byEmail['correo-malo@'];
        self::assertSame([], $malo['estudios']);
        self::assertSame('Informática', $malo['interes']);
        self::assertFalse($malo['emailValido']);

        // Los alias elegidos se recuerdan para la próxima importación.
        $again = $this->app->imports()->preview($this->table("email;curso\nnuevo@example.com;ilustración\n"), []);
        self::assertSame([], $again['estudiosSinReconocer']);
        self::assertSame(0, $again['yaExisten']);

        self::assertSame(1, count($this->app->imports()->history()));
        self::assertSame('leads.csv', $this->app->imports()->history()[0]['archivo']);
    }

    public function testDetectaExportacionesDeFacebookYAjustesDeColumnas(): void
    {
        $csv = "id\tcreated_time\tad_name\tform_id\tfull_name\temail\tphone_number\tnota_interna\n"
            . "l:1\t2026-09-14T20:00:00+0200\tMáster Moda 26\tf:1\tIrene Soto\tirene@example.com\tp:+34655000111\tllamar tarde\n";

        $preview = $this->app->imports()->preview($this->table($csv), []);
        self::assertSame('REDES_SOCIALES', $preview['origenDetectado']);
        self::assertNotContains('estudio', $preview['faltan']);
        self::assertSame('ad_name', $preview['estudioDesde'], 'sin columna de estudio, se deduce del anuncio');
        self::assertSame([6], $preview['muestra'][0]['estudioIds']);

        $report = $this->app->imports()->import($this->table($csv), 'fb.csv', ['columnas' => ['2' => 'estudio', '7' => 'comentarios']], (int) $this->admin['id']);
        self::assertSame('campana', $preview['columnas'][2]['campo'], 'ad_name era la campaña antes del ajuste');
        self::assertSame(1, $report['creados']);

        $lead = $this->app->leads()->search(['bandeja' => 'todos', 'q' => 'irene'], $this->comercial)['items'][0];
        self::assertSame('REDES_SOCIALES', $lead['origen']);
        self::assertSame([6], array_column($lead['estudios'], 'id'));
        self::assertSame('llamar tarde', $lead['comentarios']);
        self::assertSame('l:1', $lead['extra']['id']);
        self::assertSame('+34 655 00 01 11', $lead['telefono']);
    }

    /** Exportación del centro de clientes potenciales de Meta (datos inventados). */
    public function testImportaLaExportacionDeMetaConLosCursosEnElFormulario(): void
    {
        $csv = "Creado,Nombre,Correo electrónico,Origen,Formulario,Canal,Estado,Propietario,Etiquetas,Teléfono,Número de teléfono secundario,Número de WhatsApp\n"
            . "09/20/2026 10:25pm,Ana Prueba Uno,ana.prueba@example.com,De pago,F250457AA | ADGD073PO DIRECCIÓN POR OBJETIVOS Y GESTIÓN DEL DESEMPEÑO,Correo electrónico,Registrado,Unassigned,,+34600111001,,\n"
            . "09/20/2026 8:46am,Beto Prueba,beto.prueba@example.com,De pago,ARTES GRÁFICAS (F250454AA) | COMM0023 – Instagram para tu Negocio,Correo electrónico,Registrado,Unassigned,,600111002,,\n"
            . "09/19/2026 12:39am,Carla Prueba,carla.prueba@example.com,De pago,2025/18/47/EDD1/0270 MF1002_2 Inglés profesional para actividades comerciales,Correo electrónico,Registrado,Unassigned,,+34600111003,,\n"
            . "09/18/2026 1:24pm,Ana P.,ana.prueba@example.com,De pago,2025/18/47/EMD1/0223  FCOO12 – Competencias para el Desarrollo de los Recursos Humanos de la Empresa,Correo electrónico,Registrado,Unassigned,,+34600111001,,\n";
        $ids = array_column($this->app->catalogRepository()->studies(), 'id', 'nombre');

        $preview = $this->app->imports()->preview($this->table($csv), []);
        self::assertSame([], $preview['faltan']);
        self::assertSame('Formulario', $preview['estudioDesde']);
        self::assertSame([], $preview['estudiosSinReconocer']);
        self::assertSame('REDES_SOCIALES', $preview['origenDetectado'], 'formulario + propietario + WhatsApp = Meta');
        self::assertSame('2026-09-20 22:25', $preview['muestra'][0]['fecha']);
        self::assertSame([$ids['Dirección por objetivos y gestión del desempeño']], $preview['muestra'][0]['estudioIds']);
        self::assertSame([$ids['Instagram para tu negocio']], $preview['muestra'][1]['estudioIds']);
        self::assertSame([$ids['Inglés profesional para actividades comerciales']], $preview['muestra'][2]['estudioIds']);

        $report = $this->app->imports()->import($this->table($csv), 'leads.csv', [], (int) $this->comercial['id']);
        self::assertSame(3, $report['creados']);
        self::assertSame(1, $report['fusionados']);

        $ana = $this->app->leads()->search(['bandeja' => 'todos', 'q' => 'ana.prueba'], $this->comercial)['items'][0];
        self::assertSame('Ana Prueba Uno', $ana['nombreCompleto']);
        self::assertSame('REDES_SOCIALES', $ana['origen']);
        self::assertEqualsCanonicalizing(
            [$ids['Dirección por objetivos y gestión del desempeño'], $ids['Competencias para el desarrollo de los recursos humanos de la empresa']],
            array_column($ana['estudios'], 'id'),
            'la segunda solicitud añade su curso'
        );
        self::assertSame('De pago', $ana['extra']['Origen original']);
        self::assertSame('Registrado', $ana['extra']['Estado']);
        self::assertSame('2026-09-20T20:25:00Z', $ana['fechaOrigen']);
    }

    public function testUnErrorEnUnaFilaNoDetieneLaImportacion(): void
    {
        $long = str_repeat('x', 70000); // supera TEXT en MariaDB estricto
        $csv = "nombre;email;comentarios\nBien;bien@example.com;ok\nTambién;tambien@example.com;{$long}\n";

        $report = $this->app->imports()->import($this->table($csv), 'x.csv', [], (int) $this->admin['id']);

        self::assertSame(2, $report['creados'] + $report['omitidos']);
        self::assertGreaterThanOrEqual(1, $report['creados']);
    }

    public function testNumerosDeFilaConTitulosYLineasEnBlanco(): void
    {
        $table = (new SpreadsheetReader())->read(dirname(__DIR__) . '/fixtures/demo_feria.csv', 'demo_feria.csv');

        $preview = $this->app->imports()->preview($table, []);

        self::assertSame(3, $preview['filaCabecera']);
        self::assertSame([4, 5, 7], array_column($preview['muestra'], 'fila'));
        self::assertSame(3, $preview['totalFilas']);
        self::assertSame(['Sin correo ni teléfono'], $preview['muestra'][2]['avisos']);
        self::assertSame([1, 6], $preview['muestra'][1]['estudioIds']);
    }

    public function testFicheroVacio(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app->imports()->preview($this->table(''), []);
    }

    public function testPlantillaDescargable(): void
    {
        $xlsx = $this->app->imports()->templateXlsx();
        self::assertStringStartsWith('PK', $xlsx);

        $csv = $this->app->imports()->templateCsv();
        $preview = $this->app->imports()->preview($this->table($csv), []);
        self::assertSame(1, $preview['importables']);
        self::assertSame([], $preview['estudiosSinReconocer']);
        self::assertSame([], $preview['faltan']);
    }
}
