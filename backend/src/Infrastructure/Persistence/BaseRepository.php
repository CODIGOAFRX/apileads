<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use PDO;

abstract class BaseRepository
{
    public function __construct(protected readonly PDO $pdo)
    {
    }

    protected function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    protected function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    protected function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    protected function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );
        $this->execute($sql, $values);

        return (int) $this->pdo->lastInsertId();
    }

    protected function update(string $table, int $id, array $values): void
    {
        if ($values === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($values)));
        $this->execute("UPDATE {$table} SET {$sets} WHERE id = :__id", $values + ['__id' => $id]);
    }

    /**
     * Placeholders con nombre para una cláusula IN.
     *
     * @return array{0:string, 1:array<string,mixed>}
     */
    protected function inClause(string $prefix, array $values): array
    {
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $params["{$prefix}{$i}"] = $value;
        }

        return [implode(', ', array_map(static fn (string $k): string => ':' . $k, array_keys($params))), $params];
    }

    /** Ejecuta la función dentro de una transacción (reutiliza la exterior si ya hay una). */
    public function transactional(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
