<?php

declare(strict_types=1);

namespace Leads\Domain\Import;

/** Contenido tabular leído de un CSV o Excel: todas las celdas como texto. */
final class Table
{
    /** @param list<list<string>> $rows */
    public function __construct(public readonly array $rows)
    {
    }

    public function width(): int
    {
        return $this->rows === [] ? 0 : max(array_map('count', $this->rows));
    }
}
