<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use DateTimeImmutable;

/** Centros, estudios, su relación y los alias aprendidos al importar. */
final class CatalogRepository extends BaseRepository
{
    public function studies(bool $onlyActive = false): array
    {
        $rows = $this->all(
            'SELECT * FROM estudio' . ($onlyActive ? ' WHERE activo = 1' : '') . ' ORDER BY orden, nombre'
        );
        $centers = $this->all('SELECT estudio_id, centro_id FROM estudio_centro');
        $aliases = $this->all('SELECT id, estudio_id, alias FROM estudio_alias ORDER BY alias');

        return array_map(function (array $row) use ($centers, $aliases): array {
            $id = (int) $row['id'];
            return [
                'id' => $id,
                'nombre' => $row['nombre'],
                'tipo' => $row['tipo'],
                'area' => $row['area'],
                'color' => $row['color'],
                'orden' => (int) $row['orden'],
                'activo' => (bool) $row['activo'],
                'centroIds' => array_values(array_map(
                    'intval',
                    array_column(array_filter($centers, static fn ($c) => (int) $c['estudio_id'] === $id), 'centro_id')
                )),
                'alias' => array_values(array_map(
                    static fn ($a) => ['id' => (int) $a['id'], 'alias' => $a['alias']],
                    array_filter($aliases, static fn ($a) => (int) $a['estudio_id'] === $id)
                )),
            ];
        }, $rows);
    }

    public function study(int $id): ?array
    {
        foreach ($this->studies() as $study) {
            if ($study['id'] === $id) {
                return $study;
            }
        }

        return null;
    }

    /** @return list<int> ids de estudios existentes de entre los indicados */
    public function existingStudyIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $params] = $this->inClause('s', $ids);

        return array_map('intval', array_column($this->all("SELECT id FROM estudio WHERE id IN ({$in})", $params), 'id'));
    }

    public function saveStudy(?int $id, array $values, array $centerIds): int
    {
        return $this->transactional(function () use ($id, $values, $centerIds): int {
            if ($id === null) {
                $id = $this->insert('estudio', $values);
            } else {
                $this->update('estudio', $id, $values);
            }
            $this->execute('DELETE FROM estudio_centro WHERE estudio_id = :id', ['id' => $id]);
            foreach (array_unique($centerIds) as $centerId) {
                $this->insert('estudio_centro', ['estudio_id' => $id, 'centro_id' => (int) $centerId]);
            }

            return $id;
        });
    }

    public function studyHasLeads(int $id): bool
    {
        return (bool) $this->scalar('SELECT 1 FROM lead_estudio WHERE estudio_id = :id LIMIT 1', ['id' => $id]);
    }

    public function deleteStudy(int $id): void
    {
        $this->execute('DELETE FROM estudio WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,int> alias normalizado => estudio */
    public function aliasMap(): array
    {
        $map = [];
        foreach ($this->all('SELECT alias, estudio_id FROM estudio_alias') as $row) {
            $map[$row['alias']] = (int) $row['estudio_id'];
        }

        return $map;
    }

    public function saveAlias(string $alias, int $studyId, DateTimeImmutable $now): void
    {
        $existing = $this->scalar('SELECT id FROM estudio_alias WHERE alias = :alias', ['alias' => $alias]);
        if ($existing !== null) {
            $this->update('estudio_alias', (int) $existing, ['estudio_id' => $studyId]);
            return;
        }
        $this->insert('estudio_alias', ['alias' => $alias, 'estudio_id' => $studyId, 'creado_en' => DbTime::toDb($now)]);
    }

    public function deleteAlias(int $id): void
    {
        $this->execute('DELETE FROM estudio_alias WHERE id = :id', ['id' => $id]);
    }

    public function centers(): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'nombre' => $r['nombre'],
            'ciudad' => $r['ciudad'],
            'provincia' => $r['provincia'],
            'activo' => (bool) $r['activo'],
        ], $this->all('SELECT * FROM centro ORDER BY nombre'));
    }

    public function centerExists(int $id): bool
    {
        return (bool) $this->scalar('SELECT 1 FROM centro WHERE id = :id', ['id' => $id]);
    }

    public function saveCenter(?int $id, array $values): int
    {
        if ($id === null) {
            return $this->insert('centro', $values);
        }
        $this->update('centro', $id, $values);

        return $id;
    }
}
