<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

use Leads\Core\App;
use Leads\Core\FrozenClock;
use Leads\Domain\Lead\LeadInput;
use Leads\Domain\Lead\ActivityType;
use Leads\Infrastructure\Mail\LogTransport;
use Leads\Infrastructure\Persistence\Database;
use Leads\Infrastructure\Persistence\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base para pruebas con base de datos: SQLite en memoria con las mismas
 * migraciones que producción. Si TEST_DB_DSN apunta a MariaDB, se usa esa.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected FrozenClock $clock;
    protected LogTransport $mailer;
    protected App $app;
    protected array $admin;
    protected array $comercial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->connect();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();

        // Martes 15/09/2026 a las 10:00 en Madrid.
        $this->clock = new FrozenClock('2026-09-15T10:00:00+02:00');
        $this->mailer = new LogTransport();
        $this->app = new App(dirname(__DIR__, 2), $this->pdo, $this->clock, $this->mailer);

        $this->admin = $this->app->auth()->createUser([
            'nombre' => 'Ana Admin', 'email' => 'admin@test.es', 'password' => 'ClaveSegura123', 'rol' => 'ADMIN',
        ]);
        $this->comercial = $this->app->auth()->createUser([
            'nombre' => 'Marta Comercial', 'email' => 'marta@test.es', 'password' => 'ClaveSegura123', 'rol' => 'COMERCIAL',
        ]);
    }

    private function connect(): PDO
    {
        $dsn = getenv('TEST_DB_DSN');
        if (!$dsn) {
            return Database::connect('sqlite::memory:');
        }
        $pdo = Database::connect($dsn, getenv('TEST_DB_USER') ?: null, getenv('TEST_DB_PASSWORD') ?: '');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec("DROP TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return $pdo;
    }

    /** Crea un lead como si llegara por importación. */
    protected function lead(array $values): int
    {
        $input = LeadInput::fromValues($values + ['origen' => 'WEB']);

        return $this->app->leadRepository()->transactional(
            fn () => $this->app->leads()->ingest($input, (int) $this->comercial['id'], null, ActivityType::IMPORTADO)
        )['id'];
    }

    protected function ids(array $result): array
    {
        return array_map(static fn (array $lead): int => $lead['id'], $result['items']);
    }
}
