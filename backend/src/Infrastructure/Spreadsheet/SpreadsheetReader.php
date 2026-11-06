<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Spreadsheet;

use Leads\Domain\Import\Table;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Lee CSV/TXT/TSV y Excel/ODS a una {@see Table} de textos.
 *
 * CSV: detecta codificación (UTF-8, UTF-16 de Facebook, Windows-1252 de Excel)
 * y separador (coma, punto y coma, tabulador o barra vertical).
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 20000;
    private const SPREADSHEET_EXTENSIONS = ['xlsx', 'xlsm', 'xls', 'ods'];
    private const TEXT_EXTENSIONS = ['csv', 'txt', 'tsv'];

    public static function supportedExtensions(): array
    {
        return array_merge(self::TEXT_EXTENSIONS, self::SPREADSHEET_EXTENSIONS);
    }

    public function read(string $path, string $originalName): Table
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($extension, self::SPREADSHEET_EXTENSIONS, true)) {
            return $this->readSpreadsheet($path);
        }
        if (in_array($extension, self::TEXT_EXTENSIONS, true) || $extension === '') {
            return $this->readCsvString((string) file_get_contents($path));
        }

        throw new \InvalidArgumentException('Formato no admitido. Usa CSV o Excel (' . implode(', ', self::supportedExtensions()) . ')');
    }

    public function readCsvString(string $content): Table
    {
        $content = $this->toUtf8($content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        // "sep=;" es una pista que añade Excel en la primera línea.
        $delimiter = null;
        if (preg_match('/^sep=(.)\n/', $content, $m)) {
            $delimiter = $m[1];
            $content = substr($content, strlen($m[0]));
        }
        $delimiter ??= $this->detectDelimiter($content);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null]) {
                // Las líneas en blanco se conservan para que los números de fila coincidan con el fichero.
                $rows[] = [''];
                continue;
            }
            $rows[] = array_map(static fn ($v) => (string) $v, $row);
            if (count($rows) > self::MAX_ROWS + 10) {
                fclose($stream);
                throw new \InvalidArgumentException('El fichero tiene más de ' . self::MAX_ROWS . ' filas. Divídelo en varios.');
            }
        }
        fclose($stream);

        return new Table($this->trimTrailingEmptyColumns($rows));
    }

    private function readSpreadsheet(string $path): Table
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false);
            $reader->setReadEmptyCells(false);
            $book = $reader->load($path);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('No se ha podido abrir el Excel: ' . $e->getMessage());
        }

        // Se usa la hoja activa; si está vacía, la primera que tenga datos.
        $sheet = $book->getActiveSheet();
        if ($sheet->getHighestDataRow() < 2) {
            foreach ($book->getWorksheetIterator() as $candidate) {
                if ($candidate->getHighestDataRow() >= 2) {
                    $sheet = $candidate;
                    break;
                }
            }
        }
        if ($sheet->getHighestDataRow() > self::MAX_ROWS + 10) {
            throw new \InvalidArgumentException('El fichero tiene más de ' . self::MAX_ROWS . ' filas. Divídelo en varios.');
        }

        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);
            foreach ($iterator as $cell) {
                $cells[] = $this->cellToString($cell);
            }
            $rows[] = $cells;
        }
        $book->disconnectWorksheets();

        return new Table($this->trimTrailingEmptyColumns($rows));
    }

    private function cellToString(Cell $cell): string
    {
        $value = $cell->getValue();
        if ($value === null) {
            return '';
        }
        if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
            $date = ExcelDate::excelToDateTimeObject((float) $value);
            return $date->format(fmod((float) $value, 1.0) > 0 ? 'Y-m-d H:i:s' : 'Y-m-d');
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            // Teléfonos guardados como número: evita "600123454.0" o notación científica.
            return number_format($value, 0, '', '');
        }
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }
        try {
            return trim((string) ($cell->isFormula() ? $cell->getCalculatedValue() : $value));
        } catch (\Throwable) {
            return trim((string) $value);
        }
    }

    private function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($content, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }
        // UTF-16 sin BOM: la mitad de los bytes son nulos.
        if ($content !== '' && substr_count(substr($content, 0, 400), "\0") > strlen(substr($content, 0, 400)) / 3) {
            $encoding = $content[0] === "\0" ? 'UTF-16BE' : 'UTF-16LE';
            return (string) mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            return (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return $content;
    }

    private function detectDelimiter(string $content): string
    {
        $lines = array_slice(array_filter(explode("\n", $content), static fn ($l) => trim($l) !== ''), 0, 20);
        $best = ',';
        $bestScore = -1;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts = array_map(fn ($line) => $this->countOutsideQuotes($line, $candidate), $lines);
            if ($counts === [] || max($counts) === 0) {
                continue;
            }
            // Premia separadores que aparecen un número constante de veces por línea.
            $mode = array_count_values($counts);
            arsort($mode);
            $consistent = reset($mode);
            $score = $consistent * 100 + key($mode);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$candidate, $score];
            }
        }

        return $best;
    }

    private function countOutsideQuotes(string $line, string $delimiter): int
    {
        $count = 0;
        $inQuotes = false;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] === '"') {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $line[$i] === $delimiter) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<list<string>> $rows */
    private function trimTrailingEmptyColumns(array $rows): array
    {
        $width = 0;
        foreach ($rows as $row) {
            for ($i = count($row) - 1; $i >= 0; $i--) {
                if (trim((string) $row[$i]) !== '') {
                    $width = max($width, $i + 1);
                    break;
                }
            }
        }

        return array_map(static fn (array $row): array => array_slice(array_pad($row, $width, ''), 0, $width), $rows);
    }
}
