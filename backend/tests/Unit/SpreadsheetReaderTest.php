<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Domain\Import\DateParser;
use Leads\Infrastructure\Spreadsheet\ImportTemplate;
use Leads\Infrastructure\Spreadsheet\SpreadsheetReader;
use PHPUnit\Framework\TestCase;

final class SpreadsheetReaderTest extends TestCase
{
    private SpreadsheetReader $reader;

    protected function setUp(): void
    {
        $this->reader = new SpreadsheetReader();
    }

    public function testLeeCsvDeFacebookEnUtf16ConTabuladores(): void
    {
        $csv = "full_name\temail\tphone_number\nPablo Ruíz\tpablo@example.com\tp:+34611222333\n";
        $content = "\xFF\xFE" . mb_convert_encoding($csv, 'UTF-16LE', 'UTF-8');

        $table = $this->reader->readCsvString($content);

        self::assertSame([['full_name', 'email', 'phone_number'], ['Pablo Ruíz', 'pablo@example.com', 'p:+34611222333']], $table->rows);
    }

    public function testLeeCsvDeExcelEspanolEnWindows1252ConPuntoYComa(): void
    {
        $content = mb_convert_encoding("Nombre;Ubicación;Comentario\nJosé;Córdoba;\"Pregunta; con punto y coma\"\n", 'Windows-1252', 'UTF-8');

        $table = $this->reader->readCsvString($content);

        self::assertSame(['José', 'Córdoba', 'Pregunta; con punto y coma'], $table->rows[1]);
    }

    public function testRespetaSaltosDeLineaEntreComillasYQuitaColumnasVacias(): void
    {
        $table = $this->reader->readCsvString("\xEF\xBB\xBFnombre,mensaje,,\r\nAna,\"línea 1\r\nlínea 2\",,\r\n");

        self::assertSame([['nombre', 'mensaje'], ['Ana', "línea 1\nlínea 2"]], $table->rows);
    }

    public function testUsaLaPistaSepDeExcel(): void
    {
        $table = $this->reader->readCsvString("sep=|\na,b|c\n");

        self::assertSame([['a,b', 'c']], $table->rows);
    }

    public function testLeeLaPlantillaXlsx(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'plantilla');
        file_put_contents($file, (new ImportTemplate())->xlsx(['Grado Diseño Gráfico'], ['Web', 'Facebook']));

        $table = $this->reader->read($file, 'plantilla.xlsx');
        unlink($file);

        self::assertSame(ImportTemplate::HEADERS, $table->rows[0]);
        self::assertSame('600 12 34 54', $table->rows[1][3]);
    }

    public function testRechazaFormatosNoAdmitidos(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->reader->read(__FILE__, 'leads.pdf');
    }

    public function testInterpretaFechasHabituales(): void
    {
        $parser = new DateParser('Europe/Madrid');

        self::assertSame('2026-09-15 10:23', $parser->parse('2026-09-15T10:23:11+0200')->format('Y-m-d H:i'));
        self::assertSame('2026-09-15 00:00', $parser->parse('15/09/2026')->format('Y-m-d H:i'));
        self::assertSame('2026-09-15 18:05', $parser->parse('15/09/2026 18:05')->format('Y-m-d H:i'));
        self::assertSame('2026-09-15', $parser->parse('46280')->format('Y-m-d'));
        // Exportación de Meta: mes/día/año con am/pm.
        self::assertSame('2026-09-20 22:25', $parser->parse('09/20/2026 10:25pm')->format('Y-m-d H:i'));
        self::assertSame('2026-09-05 08:46', $parser->parse('09/05/2026 8:46am')->format('Y-m-d H:i'), 'con am/pm es mes/día');
        self::assertSame('2026-09-19 00:39', $parser->parse('09/19/2026 12:39 AM')->format('Y-m-d H:i'));
        self::assertSame('2026-09-20 12:05', $parser->parse('20/09/2026 12:05pm')->format('Y-m-d H:i'), 'si no cabe como mes/día, día/mes');
        self::assertNull($parser->parse('ayer'));
        self::assertNull($parser->parse('31/02/2026'));
    }
}
