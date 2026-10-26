<?php

declare(strict_types=1);

/**
 * SOLO PARA LOCAL: deja un administrador con usuario "admin" y contraseña "admin".
 *
 * Se salta a propósito las reglas normales (correo válido, contraseña de 10+ caracteres)
 * para entrar rápido mientras se desarrolla. Por seguridad solo actúa con APP_DEBUG=1,
 * así que en el servidor (Plesk, APP_DEBUG=0) no hace nada.
 *
 * Uso: php bin/admin-local.php   (el arranque de Docker lo lanza solo)
 */

use Leads\Core\App;
use Leads\Core\Env;
use Leads\Domain\User\Role;
use Leads\Infrastructure\Persistence\DbTime;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::load($root . '/.env');

if (!Env::bool('APP_DEBUG')) {
    fwrite(STDERR, "admin-local: APP_DEBUG no está activo; no se crea el usuario admin/admin (solo local).\n");
    exit(0);
}

const USUARIO = 'admin';
const CLAVE = 'admin';

$pdo = (new App($root))->pdo();
$hash = password_hash(CLAVE, PASSWORD_DEFAULT);

$find = $pdo->prepare('SELECT id FROM usuario WHERE email = :email');
$find->execute(['email' => USUARIO]);
$id = $find->fetchColumn();

if ($id === false) {
    $pdo->prepare(
        'INSERT INTO usuario (nombre, email, password_hash, rol, activo, creado_en)
         VALUES (:nombre, :email, :hash, :rol, 1, :creado)'
    )->execute([
        'nombre' => 'Admin (local)',
        'email' => USUARIO,
        'hash' => $hash,
        'rol' => Role::ADMIN->value,
        'creado' => DbTime::toDb(new DateTimeImmutable()),
    ]);
    echo "Usuario admin/admin creado (solo local).\n";
} else {
    // Si ya existía, se restablece por si se cambió la clave o el rol.
    $pdo->prepare('UPDATE usuario SET password_hash = :hash, rol = :rol, activo = 1 WHERE id = :id')
        ->execute(['hash' => $hash, 'rol' => Role::ADMIN->value, 'id' => (int) $id]);
    echo "Usuario admin/admin restablecido (solo local).\n";
}
