<?php

declare(strict_types=1);

/**
 * Datos de demostración para ver la aplicación funcionando en local.
 *
 *   php backend/bin/demo.php            (no hace nada si ya hay leads)
 *   php backend/bin/demo.php --force    (añade otra tanda igualmente)
 *
 * Todo es inventado: nombres, correos @example.com y teléfonos que no existen.
 * NO usar en producción.
 */

use Leads\Core\App;
use Leads\Core\Env;
use Leads\Domain\Lead\ActivityType;
use Leads\Domain\Lead\LeadInput;
use Leads\Infrastructure\Persistence\DbTime;
use Leads\Infrastructure\Persistence\Migrator;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::load($root . '/.env');

$app = new App($root);
(new Migrator($app->pdo(), $root . '/database/migrations'))->migrate();

$force = in_array('--force', $argv, true);
$existing = (int) $app->pdo()->query('SELECT COUNT(*) FROM leads')->fetchColumn();
if ($existing > 0 && !$force) {
    echo "Ya hay {$existing} leads: no se toca nada. Usa --force para añadir la demostración igualmente.\n";
    exit(0);
}

// ─── Usuarios ────────────────────────────────────────────────────────────────
$auth = $app->auth();
$auth->ensureBootstrapAdmin(Env::get('ADMIN_EMAIL', 'admin@estaciondiseno.es'), Env::get('ADMIN_PASSWORD', 'AdminLocal2026'));

$comerciales = [];
foreach ([['Marta Ruiz', 'marta@estaciondiseno.es'], ['Nuria Prado', 'nuria@estaciondiseno.es']] as [$nombre, $email]) {
    try {
        $comerciales[] = $auth->createUser(['nombre' => $nombre, 'email' => $email, 'password' => 'DemoLocal2026', 'rol' => 'COMERCIAL']);
        echo "Usuario creado: {$email} / DemoLocal2026\n";
    } catch (Throwable) {
        echo "El usuario {$email} ya existía.\n";
    }
}
$marta = $comerciales[0]['id'] ?? (int) $app->pdo()->query("SELECT id FROM usuario WHERE rol = 'COMERCIAL' ORDER BY id LIMIT 1")->fetchColumn();

// ─── Leads ───────────────────────────────────────────────────────────────────
$nombres = ['Lucía', 'Pablo', 'Andrés', 'María', 'Carmen', 'Javier', 'Irene', 'Sergio', 'Alba', 'Hugo', 'Nerea', 'Álvaro', 'Paula', 'Marcos', 'Sofía', 'Daniel', 'Elena', 'Rubén', 'Claudia', 'Adrián', 'Noelia', 'Iván', 'Marta', 'Raúl', 'Laura', 'Óscar', 'Sara', 'Diego', 'Ainhoa', 'Mario'];
$apellidos = ['García', 'Martín', 'López', 'Ruiz', 'Fernández', 'Moreno', 'Jiménez', 'Romero', 'Navarro', 'Torres', 'Domínguez', 'Vázquez', 'Ramos', 'Gil', 'Serrano', 'Molina', 'Castro', 'Ortega', 'Delgado', 'Morales'];
$ciudades = ['Granada', 'Jaén', 'Málaga', 'Almería', 'Córdoba', 'Sevilla', 'Motril', 'Armilla', 'Loja', 'Guadix'];
$intereses = [
    'Grado Diseño Gráfico', 'grado diseño moda', 'Máster ilustración', 'CFGS Ilustración', 'cómic',
    'Gráfica audiovisual', 'animación 3D', 'Máster profesional gráfico', 'gráfica interactiva',
    'Máster moda', 'ilustración', 'diseño gráfico', 'Informática', '',
];
$origenes = ['WEB', 'WEB', 'WEB', 'FACEBOOK', 'FACEBOOK', 'INSTAGRAM', 'GOOGLE', 'FERIA'];
$campanas = ['Captación 26/27', 'Jornada puertas abiertas', 'Remarketing septiembre', null];
$comentarios = ['Pregunta por becas', 'Quiere ver las aulas', 'Viene de otro ciclo', 'Solo por la tarde', null, null];

$studies = $app->catalogRepository()->studies(onlyActive: true);
$matcher = new Leads\Domain\Import\StudyMatcher($studies, $app->catalogRepository()->aliasMap());
$leads = $app->leads();
$pdo = $app->pdo();

mt_srand(2026);
$creados = [];
$pdo->beginTransaction();
for ($i = 0; $i < 45; $i++) {
    $nombre = $nombres[array_rand($nombres)];
    $apellido = $apellidos[array_rand($apellidos)] . ' ' . $apellidos[array_rand($apellidos)];
    $interes = $intereses[array_rand($intereses)];
    $sinTelefono = $i % 11 === 0;
    $sinCorreo = $i % 9 === 4;
    $dias = mt_rand(0, 12);

    $input = LeadInput::fromValues([
        'nombre' => $nombre,
        'apellidos' => $apellido,
        'email' => $sinCorreo ? null : sprintf('%s.%s%d@example.com', mb_strtolower(iconvName($nombre)), mb_strtolower(iconvName(explode(' ', $apellido)[0])), $i),
        'telefono' => $sinTelefono ? null : sprintf('600 00%d %03d', $i % 10, 100 + $i),
        'ubicacion' => $ciudades[array_rand($ciudades)],
        'interes' => $interes,
        'estudioIds' => $matcher->match($interes)['ids'],
        'origen' => $origenes[array_rand($origenes)],
        'campana' => $campanas[array_rand($campanas)],
        'comentarios' => $comentarios[array_rand($comentarios)],
        'fechaOrigen' => new DateTimeImmutable("-{$dias} days -" . mt_rand(0, 8) . ' hours'),
    ]);

    $result = $leads->ingest($input, $marta, null, ActivityType::IMPORTADO);
    $creados[] = ['id' => $result['id'], 'dias' => $dias];
}
$pdo->commit();

// Las fechas de entrada se reparten en los últimos días para que el listado no salga todo igual.
$update = $pdo->prepare('UPDATE leads SET ultima_entrada = :fecha, creado_en = :fecha2 WHERE id = :id');
foreach ($creados as $lead) {
    $fecha = DbTime::toDb(new DateTimeImmutable("-{$lead['dias']} days -" . mt_rand(0, 300) . ' minutes'));
    $update->execute(['fecha' => $fecha, 'fecha2' => $fecha, 'id' => $lead['id']]);
}

// ─── Algo de historia: llamadas, interesados, descartados y una matrícula ─────
$viewer = ['id' => $marta, 'nombre' => 'Marta Ruiz', 'email' => 'marta@estaciondiseno.es', 'rol' => 'COMERCIAL'];
$conTelefono = array_values(array_filter(
    array_map(static fn (array $l): array => $leads->findOrFail($l['id']), $creados),
    static fn (array $row): bool => $row['telefono_digitos'] !== null
));

$guion = [
    ['NO_CONTESTA', 1, 'Buzón de voz'],
    ['NO_CONTESTA', 3, null],
    ['INTERESADO', 1, 'Quiere visitar la escuela'],
    ['NO_INTERESADO', 1, 'Se ha matriculado en otro sitio'],
];
$indice = 0;
foreach ($guion as [$resultado, $veces, $nota]) {
    foreach (range(1, 3) as $_) {
        $lead = $conTelefono[$indice++] ?? null;
        if ($lead === null) {
            break;
        }
        for ($vuelta = 0; $vuelta < $veces; $vuelta++) {
            $leads->registerCall((int) $lead['id'], $resultado, $vuelta === 0 ? $nota : null, $viewer);
        }
    }
}

// Dos llamadas agendadas: una para mañana y otra cuya hora ya ha llegado (sale la primera, en verde).
$madrid = new DateTimeZone('Europe/Madrid');
$agendados = array_slice($conTelefono, $indice, 2);
foreach ($agendados as $n => $lead) {
    $leads->scheduleCall((int) $lead['id'], (new DateTimeImmutable('+1 day', $madrid))->format('Y-m-d\T17:00'), 'Prefiere por la tarde', $viewer);
    if ($n === 1) {
        $pdo->prepare('UPDATE leads SET llamada_agendada = :fecha WHERE id = :id')
            ->execute(['fecha' => DbTime::toDb(new DateTimeImmutable('-1 hour')), 'id' => $lead['id']]);
    }
}

// Una cita y una matrícula para que se vean todas las pestañas.
$interesados = $leads->search(['bandeja' => 'interesados'], $viewer)['items'];
if (isset($interesados[0])) {
    $leads->scheduleAppointment($interesados[0]['id'], (new DateTimeImmutable('+2 days'))->format('Y-m-d\T11:30'), 'Visita a las aulas', $viewer);
}
if (isset($interesados[1])) {
    $leads->enroll($interesados[1]['id'], 'Matrícula formalizada en secretaría', $viewer);
}

$contadores = $leads->counters();
echo "\nLeads de demostración creados (todos inventados: no llames a esos números).\n";
foreach ($contadores as $bandeja => $total) {
    printf("  %-14s %d\n", $bandeja, $total);
}
echo "\nEntra con admin (ver .env) o con marta@estaciondiseno.es / DemoLocal2026\n";

/** Quita las tildes para construir correos de ejemplo. */
function iconvName(string $value): string
{
    return (string) preg_replace('/[^a-zA-Z]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $value));
}
