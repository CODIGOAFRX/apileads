<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use PDO;

/**
 * Traduce los pocos tokens que difieren entre MariaDB (producción) y SQLite
 * (pruebas), para mantener una única fuente de verdad del esquema.
 *
 *   {PK}      clave primaria autoincremental
 *   {TABLE}   opciones de tabla (motor y cotejamiento)
 *   {BOOL}    booleano
 *   {JSON}    texto largo con JSON
 *   {BLOB}    fichero binario (hasta 4 GB en MariaDB)
 */
final class Dialect
{
    private const TOKENS = [
        'mysql' => [
            '{PK}' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            '{FK}' => 'INT UNSIGNED',
            '{TABLE}' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            '{BOOL}' => 'TINYINT(1)',
            '{JSON}' => 'LONGTEXT',
            '{BLOB}' => 'LONGBLOB',
        ],
        'sqlite' => [
            '{PK}' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            '{FK}' => 'INTEGER',
            '{TABLE}' => '',
            '{BOOL}' => 'INTEGER',
            '{JSON}' => 'TEXT',
            '{BLOB}' => 'BLOB',
        ],
    ];

    public function __construct(private readonly string $driver)
    {
        if (!isset(self::TOKENS[$driver])) {
            throw new \RuntimeException("Motor de base de datos no soportado: {$driver}");
        }
    }

    public static function for(PDO $pdo): self
    {
        return new self((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function sql(string $statement): string
    {
        return strtr($statement, self::TOKENS[$this->driver]);
    }

    public function isMysql(): bool
    {
        return $this->driver === 'mysql';
    }
}
