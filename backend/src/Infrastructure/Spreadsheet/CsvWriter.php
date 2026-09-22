<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Spreadsheet;

/** CSV compatible con Excel en español (punto y coma y BOM UTF-8). */
final class CsvWriter
{
    /** @param iterable<list<string|int|null>> $rows */
    public function build(array $headers, iterable $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map([self::class, 'safe'], $row), ';', '"', '');
        }
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF" . $content;
    }

    /** Evita inyección de fórmulas al abrir el CSV en Excel. */
    public static function safe(mixed $value): string
    {
        $value = (string) ($value ?? '');
        return preg_match('/^[=+\-@\t\r]/', $value) && !preg_match('/^\+\d[\d\s]*$/', $value) ? "'" . $value : $value;
    }
}
