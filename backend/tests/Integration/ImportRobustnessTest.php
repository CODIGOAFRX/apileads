<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

use Leads\Domain\Import\Table;
use Leads\Infrastructure\Spreadsheet\SpreadsheetReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Importar es la puerta por la que entra de todo: ficheros vacíos, dañados,
 * con otra extensión, binarios, gigantes… El resultado siempre tiene que ser una
 * tabla o un mensaje claro (InvalidArgumentException → 422), nunca un fallo.
 */
final class ImportRobustnessTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }

    public static function junk(): array
    {
        mt_srand(2026);
        $random = static fn (int $bytes): string => implode('', array_map(static fn () => chr(mt_rand(0, 255)), range(1, $bytes)));
        $cases = [];
        foreach (['xlsx', 'xls', 'ods', 'csv', 'txt', 'tsv', ''] as $extension) {
            $cases["vacío .{$extension}"] = ['', $extension];
            $cases["binario .{$extension}"] = [$random(4096), $extension];
            $cases["zip roto .{$extension}"] = ["PK\x03\x04" . $random(500), $extension];
        }

        return $cases + [
            'solo BOM UTF-8' => ["\xEF\xBB\xBF", 'csv'],
            'solo BOM UTF-16' => ["\xFF\xFE", 'csv'],
            'UTF-16 impar' => ["\xFF\xFEa\x00b", 'csv'],
            'comillas sin cerrar' => ["nombre;email\n\"Eva;eva@example.com\n", 'csv'],
            'una línea enorme' => [str_repeat('a;', 50000), 'csv'],
            'saltos de línea raros' => ["nombre\r\r\n\n\rEva\r", 'csv'],
            'HTML con extensión xls' => ['<html><table><tr><td>Eva</td></tr></table></html>', 'xls'],
            'sep= sin nada más' => ["sep=;\n", 'csv'],
            'bytes nulos' => ["nombre;telefono\nEva\0;600\0123\0456\n", 'csv'],
        ];
    }

    #[DataProvider('junk')]
    public function testUnFicheroMaloNoRompeNada(string $content, string $extension): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $content);
        try {
            $table = (new SpreadsheetReader())->read($path, 'leads' . ($extension === '' ? '' : ".{$extension}"));
            self::assertIsList($table->rows);
            // Y con lo que haya salido, la previsualización y la importación tampoco fallan.
            $this->app->imports()->preview($table, []);
            $this->app->imports()->import($table, 'leads.csv', [], (int) $this->comercial['id']);
        } catch (\InvalidArgumentException $e) {
            self::assertNotSame('', $e->getMessage(), 'el error se explica');
        } finally {
            unlink($path);
        }
    }

    public function testUnExcelConFormatoHastaLaUltimaColumnaSeLeeSinAgotarLaMemoria(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Nombre', 'Teléfono'], ['Eva', '600123456']]);
        // Alguien pintó una celda al final de la hoja: no debe leerse la fila de 16.384 columnas.
        $sheet->setCellValue('XFD1', ' ');
        $path = (string) tempnam(sys_get_temp_dir(), 'xls') . '.xlsx';
        (new Xlsx($book))->save($path);

        $started = microtime(true);
        $table = (new SpreadsheetReader())->read($path, 'leads.xlsx');
        unlink($path);

        self::assertLessThan(5, microtime(true) - $started);
        self::assertSame(['Nombre', 'Teléfono'], $table->rows[0]);
    }
}
