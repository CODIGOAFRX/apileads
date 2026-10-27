<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

final class ImportRepository extends BaseRepository
{
    public function create(array $values): int
    {
        return $this->insert('importacion', $values);
    }

    public function save(int $id, array $values): void
    {
        $this->update('importacion', $id, $values);
    }

    public function latest(int $limit = 20): array
    {
        $rows = $this->all(
            'SELECT i.*, u.nombre AS usuario FROM importacion i LEFT JOIN usuario u ON u.id = i.usuario_id
             ORDER BY i.creado_en DESC, i.id DESC LIMIT ' . max(1, min(100, $limit))
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'archivo' => $r['archivo'],
            'origen' => $r['origen'],
            'usuario' => $r['usuario'],
            'totalFilas' => (int) $r['total_filas'],
            'creados' => (int) $r['creados'],
            'fusionados' => (int) $r['fusionados'],
            'omitidos' => (int) $r['omitidos'],
            'fecha' => DbTime::toApi($r['creado_en']),
        ], $rows);
    }
}
