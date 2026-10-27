<?php

declare(strict_types=1);

/**
 * Deja la base de datos lista para producción: borra lo inventado y el rastro de las pruebas.
 *
 *   php bin/limpiar-pruebas.php            → solo muestra lo que haría (no toca nada)
 *   php bin/limpiar-pruebas.php --aplicar  → lo hace (todo o nada, en una transacción)
 *
 * En Docker:  docker compose exec app php backend/bin/limpiar-pruebas.php [--aplicar]
 *
 * Qué hace:
 *  - Borra los leads inventados: los de bin/demo.php (entraron como «importados» sin fichero)
 *    y cualquiera con correo @example.* (dominio reservado para pruebas).
 *  - A los leads reales los deja como recién llegados: pendientes, 0 intentos, sin llamadas,
 *    citas, agendas, descartes ni reservas. De su historial solo queda cómo entraron.
 *  - Vacía correos enviados, registro de actividad, intentos de acceso y el límite de envío.
 *  - Borra los usuarios de prueba (admin/admin local y las comerciales de la demostración).
 *  - Quita del historial de importaciones las que ya no tienen ningún lead.
 * No toca: usuarios reales, estudios, alias, centros, plantillas de correo ni dossieres.
 */

use Leads\Core\App;
use Leads\Core\Env;
use Leads\Infrastructure\Persistence\DbTime;
use Leads\Infrastructure\Persistence\ThrottleRepository;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::load($root . '/.env');

$apply = in_array('--aplicar', $argv, true);
$pdo = (new App($root))->pdo();

/** Usuarios que crean bin/admin-local.php y bin/demo.php. */
const USUARIOS_DE_PRUEBA = ['admin', 'marta@estaciondiseno.es', 'nuria@estaciondiseno.es'];
/** Lo que queda en el historial de un lead real: cómo entró. */
const ENTRADAS = ['IMPORTADO', 'CREADO'];

$col = static fn (string $sql, array $params = []): array => (static function () use ($pdo, $sql, $params): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_COLUMN);
})();
$rows = static function (string $sql, array $params = []) use ($pdo): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$count = static fn (string $table): int => (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
$in = static fn (array $ids): string => $ids === [] ? '0' : implode(',', array_map('intval', $ids));

// ─── Qué es inventado ─────────────────────────────────────────────────────────
// demo.php registra sus leads como IMPORTADO pero sin importación (un fichero real siempre la tiene).
$demo = $col(
    "SELECT DISTINCT l.id FROM leads l JOIN lead_actividad a ON a.lead_id = l.id
     WHERE l.importacion_id IS NULL AND a.tipo = 'IMPORTADO'"
);
$example = $col(
    "SELECT id FROM leads WHERE LOWER(email) LIKE '%@example.com' OR LOWER(email) LIKE '%@example.org'
        OR LOWER(email) LIKE '%@example.net' OR LOWER(email) LIKE '%@example.es' OR LOWER(email) LIKE '%.test'"
);
$fake = array_values(array_unique(array_map('intval', array_merge($demo, $example))));
sort($fake);
$total = $count('leads');
$real = $total - count($fake);

$testUsers = $rows(
    'SELECT id, nombre, email FROM usuario WHERE email IN (' . implode(',', array_fill(0, count(USUARIOS_DE_PRUEBA), '?')) . ')',
    USUARIOS_DE_PRUEBA
);
$keptUsers = $rows(
    'SELECT nombre, email, rol FROM usuario WHERE id NOT IN (' . $in(array_column($testUsers, 'id')) . ') ORDER BY id'
);
$manual = $rows(
    'SELECT id, nombre, apellidos, email, telefono FROM leads
     WHERE importacion_id IS NULL AND id NOT IN (' . $in($fake) . ') ORDER BY id'
);
$touched = (int) $pdo->query(
    "SELECT COUNT(*) FROM leads WHERE id NOT IN ({$in($fake)}) AND (estado <> 'NUEVO' OR intentos_sin_respuesta > 0
        OR ultima_llamada IS NOT NULL OR proxima_llamada IS NOT NULL OR llamada_agendada IS NOT NULL
        OR cita_en IS NOT NULL OR bloqueado_por IS NOT NULL)"
)->fetchColumn();
$userStudies = $rows('SELECT nombre FROM estudio WHERE creado_por IS NOT NULL ORDER BY id');
$mailboxes = $rows(
    'SELECT u.email FROM cuenta_correo c JOIN usuario u ON u.id = c.usuario_id
     WHERE c.usuario_id NOT IN (' . $in(array_column($testUsers, 'id')) . ')'
);

// ─── Informe ──────────────────────────────────────────────────────────────────
echo $apply ? "LIMPIANDO LA BASE DE DATOS\n\n" : "SIMULACIÓN (no se toca nada; para hacerlo: --aplicar)\n\n";
printf("Leads: %d en total → se borran %d inventados (demostración: %d, correo de prueba: %d) y quedan %d reales.\n",
    $total, count($fake), count($demo), count($example), $real);
printf("Leads reales con llamadas, estados o reservas de las pruebas que vuelven a «pendientes» desde cero: %d\n", $touched);
printf("Historial: se borran %d de %d entradas (se conserva solo cómo entró cada lead real).\n",
    (int) $pdo->query("SELECT COUNT(*) FROM lead_actividad WHERE lead_id IN ({$in($fake)}) OR tipo NOT IN ('IMPORTADO','CREADO')")->fetchColumn(),
    $count('lead_actividad'));
printf("Correos enviados: %d · Registro de actividad: %d · Intentos de acceso: %d → se vacían.\n",
    $count('envio_correo'), $count('registro'), $count('login_intento'));
echo "\nUsuarios de prueba que se borran:\n";
foreach ($testUsers as $u) {
    echo "  - {$u['nombre']} <{$u['email']}>\n";
}
if ($testUsers === []) {
    echo "  (ninguno)\n";
}
echo "Usuarios que se quedan:\n";
foreach ($keptUsers as $u) {
    echo "  - {$u['nombre']} <{$u['email']}> ({$u['rol']})\n";
}
if ($manual !== []) {
    echo "\nLeads reales dados de alta a mano (se conservan; revisa que ninguno sea de prueba):\n";
    foreach ($manual as $l) {
        echo "  #{$l['id']} " . trim("{$l['nombre']} {$l['apellidos']}") . ' · ' . ($l['email'] ?? '—') . ' · ' . ($l['telefono'] ?? '—') . "\n";
    }
}
if ($userStudies !== []) {
    echo "\nEstudios creados desde la aplicación (se conservan): " . implode(', ', array_column($userStudies, 'nombre')) . "\n";
}
if ($mailboxes !== []) {
    echo "\nOJO: buzones de envío guardados (" . implode(', ', array_column($mailboxes, 'email')) . "): su contraseña va cifrada con el\n"
        . "JWT_SECRET local, así que en el servidor habrá que volver a escribirla en Ajustes → Usuarios.\n";
}

if (!$apply) {
    echo "\nNo se ha cambiado nada.\n";
    exit(0);
}

// ─── Limpieza ─────────────────────────────────────────────────────────────────
$now = DbTime::toDb(new DateTimeImmutable());
$pdo->beginTransaction();
try {
    $fakeIn = $in($fake);
    // Hijos primero (también hay ON DELETE CASCADE, pero así no depende del motor).
    $pdo->exec("DELETE FROM lead_estudio WHERE lead_id IN ({$fakeIn})");
    $pdo->exec('DELETE FROM envio_correo');
    $pdo->exec('DELETE FROM registro');
    $pdo->exec("DELETE FROM lead_actividad WHERE lead_id IN ({$fakeIn}) OR tipo NOT IN ('" . implode("','", ENTRADAS) . "')");
    $pdo->exec("DELETE FROM leads WHERE id IN ({$fakeIn})");

    $reset = $pdo->prepare(
        "UPDATE leads SET estado = 'NUEVO', intentos_sin_respuesta = 0, proxima_llamada = NULL, ultima_llamada = NULL,
            llamada_agendada = NULL, cita_en = NULL, motivo_descarte = NULL, bloqueado_por = NULL, bloqueado_hasta = NULL,
            actualizado_en = :ahora"
    );
    $reset->execute(['ahora' => $now]);

    $pdo->exec('DELETE FROM importacion WHERE id NOT IN (SELECT importacion_id FROM leads WHERE importacion_id IS NOT NULL)');
    $pdo->exec('DELETE FROM login_intento');
    // Se deja libre, sin borrar la fila: sin ella no se reservaría nunca el turno de envío.
    $pdo->exec('DELETE FROM limite_envio');
    $pdo->exec("INSERT INTO limite_envio (clave, ultimo) VALUES ('correo', '" . ThrottleRepository::FREE . "')");

    $testIds = $in(array_column($testUsers, 'id'));
    $pdo->exec("DELETE FROM cuenta_correo WHERE usuario_id IN ({$testIds})");
    foreach (['leads' => ['creado_por', 'bloqueado_por'], 'lead_actividad' => ['usuario_id'], 'importacion' => ['usuario_id'], 'estudio' => ['creado_por']] as $table => $columns) {
        foreach ($columns as $column) {
            $pdo->exec("UPDATE {$table} SET {$column} = NULL WHERE {$column} IN ({$testIds})");
        }
    }
    $pdo->exec("DELETE FROM usuario WHERE id IN ({$testIds})");
    $pdo->exec('UPDATE usuario SET ultimo_acceso = NULL');

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nNo se ha cambiado nada: {$e->getMessage()}\n");
    exit(1);
}

printf("\nHecho. Quedan %d leads, todos en pendientes; %d usuarios.\n", $count('leads'), $count('usuario'));
