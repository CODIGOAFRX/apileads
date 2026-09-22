<?php

declare(strict_types=1);

/**
 * Crea o actualiza las tablas y el primer administrador.
 * Uso: php bin/migrate.php   (o como tarea programada "Ejecutar script PHP" en Plesk)
 */

use Leads\Core\App;
use Leads\Core\Env;
use Leads\Infrastructure\Persistence\Migrator;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::load($root . '/.env');

$app = new App($root);
$done = (new Migrator($app->pdo(), $root . '/database/migrations'))->migrate();
echo $done === [] ? "La base de datos ya estaba al día.\n" : "Migraciones aplicadas:\n - " . implode("\n - ", $done) . "\n";

$app->auth()->ensureBootstrapAdmin(Env::get('ADMIN_EMAIL'), Env::get('ADMIN_PASSWORD'));
echo "Listo.\n";
