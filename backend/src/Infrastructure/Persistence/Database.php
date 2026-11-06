<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use PDO;

final class Database
{
    public static function connect(string $dsn, ?string $user = null, ?string $password = null): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        $pdo = new PDO($dsn, $user, $password, $options);
        if (self::isMysql($pdo)) {
            // Todas las fechas se guardan y comparan en UTC.
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec("SET time_zone = '+00:00'");
        } else {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $pdo;
    }

    public static function isMysql(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
