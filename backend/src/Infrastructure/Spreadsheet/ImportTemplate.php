<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Plantilla descargable para importar leads. */
final class ImportTemplate
{
    public const HEADERS = ['Nombre', 'Apellidos', 'Email', 'Teléfono', 'Ubicación', 'Estudio de interés', 'Origen', 'Campaña', 'Fecha', 'Comentarios'];

    private const EXAMPLE = ['Lucía', 'Martín Pérez', 'lucia.martin@example.com', '600 12 34 54', 'Granada', 'Grado Diseño Gráfico', 'Web', 'Jornada puertas abiertas', '15/09/2026', 'Quiere información de becas'];

    /** @param list<string> $studyNames */
    public function xlsx(array $studyNames, array $origins): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Leads');
        $sheet->fromArray([self::HEADERS, self::EXAMPLE]);
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);
        $sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('00FF81');
        $sheet->getStyle('D:D')->getNumberFormat()->setFormatCode('@');
        $sheet->freezePane('A2');
        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setWidth($column === 'F' ? 42 : 22);
        }

        $lists = $book->createSheet();
        $lists->setTitle('Listas');
        $lists->setCellValue('A1', 'Estudios');
        $lists->setCellValue('B1', 'Orígenes');
        foreach (array_values($studyNames) as $i => $name) {
            $lists->setCellValue('A' . ($i + 2), $name);
        }
        foreach (array_values($origins) as $i => $origin) {
            $lists->setCellValue('B' . ($i + 2), $origin);
        }
        $lists->getColumnDimension('A')->setWidth(45);
        $lists->getColumnDimension('B')->setWidth(20);

        $this->addListValidation($sheet, 'F2:F5000', sprintf("'Listas'!\$A\$2:\$A\$%d", count($studyNames) + 1));
        $this->addListValidation($sheet, 'G2:G5000', sprintf("'Listas'!\$B\$2:\$B\$%d", count($origins) + 1));

        $help = $book->createSheet();
        $help->setTitle('Instrucciones');
        $help->fromArray(array_map(static fn ($l) => [$l], [
            'Cómo rellenar la plantilla',
            '• Ninguna columna es obligatoria: basta con nombre, correo o teléfono.',
            '• Puedes dejar celdas vacías, cambiar el orden de las columnas o añadir otras: se guardan como datos extra.',
            '• El estudio puede escribirse como quieras; si el programa no lo reconoce te preguntará al importar.',
            '• Varios estudios en la misma celda: sepáralos con coma.',
            '• Si el correo o el teléfono ya existen, se completa el lead existente en lugar de duplicarlo.',
            '• Borra la fila de ejemplo antes de importar.',
        ]));
        $help->getColumnDimension('A')->setWidth(110);
        $help->getStyle('A1')->getFont()->setBold(true);
        $book->setActiveSheetIndex(0);

        $stream = fopen('php://temp', 'r+');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();

        return $content;
    }

    public function csv(): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, self::HEADERS, ';', '"', '');
        fputcsv($stream, self::EXAMPLE, ';', '"', '');
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        // BOM para que Excel en español abra bien las tildes.
        return "\xEF\xBB\xBF" . $content;
    }

    private function addListValidation($sheet, string $range, string $formula): void
    {
        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(true);
        $validation->setShowErrorMessage(false);
        $validation->setFormula1($formula);
        $sheet->setDataValidation($range, $validation);
    }
}
