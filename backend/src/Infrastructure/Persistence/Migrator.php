<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use PDO;

/**
 * Aplica, en orden y una sola vez, los ficheros de database/migrations.
 * Cada fichero devuelve una lista de sentencias SQL con tokens de {@see Dialect}.
 * Es idempotente: se puede ejecutar en cada despliegue.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> nombres de las migraciones aplicadas en esta ejecución */
    public function migrate(): array
    {
        $dialect = Dialect::for($this->pdo);
        $this->pdo->exec($dialect->sql(
            'CREATE TABLE IF NOT EXISTS migracion (nombre VARCHAR(190) NOT NULL PRIMARY KEY, aplicada_en DATETIME NOT NULL) {TABLE}'
        ));

        $applied = $this->pdo->query('SELECT nombre FROM migracion')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(rtrim($this->directory, '/') . '/*.php') ?: [];
        sort($files);

        $done = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $applied, true)) {
                continue;
            }

            /** @var list<string> $statements */
            $statements = (static fn (string $path): array => require $path)($file);
            foreach ($statements as $statement) {
                $this->pdo->exec($dialect->sql($statement));
            }

            $stmt = $this->pdo->prepare('INSERT INTO migracion (nombre, aplicada_en) VALUES (?, ?)');
            $stmt->execute([$name, gmdate('Y-m-d H:i:s')]);
            $done[] = $name;
        }

        return $done;
    }
}
