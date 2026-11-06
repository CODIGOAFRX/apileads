<?php

declare(strict_types=1);

namespace Leads\Domain\Import;

/** Resultado del análisis de columnas de un fichero. */
final class ColumnMapping
{
    /**
     * @param list<string> $headers
     * @param array<int, ImportField> $fields
     * @param array<int, list<string>> $dataRows filas de datos indexadas por su posición en el fichero (base 0)
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $fields,
        public readonly array $dataRows,
        public readonly ?int $headerRow,
        public readonly bool $looksLikeFacebook,
    ) {
    }

    /**
     * Aplica los cambios que la comercial haga en la previsualización.
     *
     * @param array<int|string, string> $overrides índice de columna => campo
     */
    public function withOverrides(array $overrides): self
    {
        $fields = $this->fields;
        foreach ($overrides as $index => $value) {
            $field = ImportField::tryFrom((string) $value);
            if ($field !== null && array_key_exists((int) $index, $fields)) {
                $fields[(int) $index] = $field;
            }
        }
        // Si se asigna un campo único a una columna, se libera la que lo tenía.
        foreach ($overrides as $index => $value) {
            $field = ImportField::tryFrom((string) $value);
            if ($field === null || !$field->isSingle()) {
                continue;
            }
            foreach ($fields as $i => $current) {
                if ($i !== (int) $index && $current === $field) {
                    $fields[$i] = ImportField::EXTRA;
                }
            }
        }

        return new self($this->headers, $fields, $this->dataRows, $this->headerRow, $this->looksLikeFacebook);
    }

    public function has(ImportField $field): bool
    {
        return in_array($field, $this->fields, true);
    }

    /**
     * Sin columna de estudio, el estudio se deduce de la de campaña o formulario
     * (en Meta el formulario es el curso). Devuelve su cabecera, o null.
     */
    public function studyFallbackHeader(): ?string
    {
        if ($this->has(ImportField::ESTUDIO)) {
            return null;
        }
        $index = array_search(ImportField::CAMPANA, $this->fields, true);

        return $index === false ? null : $this->headers[$index];
    }

    public function describe(): array
    {
        $columns = [];
        foreach ($this->headers as $i => $header) {
            $columns[] = [
                'indice' => $i,
                'cabecera' => $header,
                'campo' => ($this->fields[$i] ?? ImportField::EXTRA)->value,
                'ejemplos' => array_values(array_slice(array_filter(
                    array_map(static fn (array $row): string => trim((string) ($row[$i] ?? '')), array_slice($this->dataRows, 0, 30)),
                    static fn (string $v): bool => $v !== ''
                ), 0, 3)),
            ];
        }

        return $columns;
    }
}
