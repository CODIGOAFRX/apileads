<?php

/**
 * Cada estudio es de Estación Diseño o de Grupo EFP (ver Leads\Domain\Lead\Company).
 * Son de Estación Diseño los que ofrece estaciondiseno.es en sus apartados de estudios
 * (grados, ciclos, másteres, cursos cortos y formación subvencionada); se crean los que
 * faltaban. Todos los demás, y los que se creen después salvo que se diga otra cosa,
 * son de Grupo EFP.
 */
$quote = static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'";

// Los diez estudios de fábrica (2026_09_17_000002) y el Máster Profesional de Ilustración Avanzada.
$estacion = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
$existentes = ['Máster Profesional Ilustración Avanzada'];

$nuevos = [
    // [nombre, color] Sin comas en el nombre: al importar, la coma separa estudios.
    ['Programa superior en gestión integral de eventos (espectáculos y proyectos audiovisuales)', '#8A86FF'],
    ['Gestión integral de eventos (espectáculos y proyectos audiovisuales)', '#8A86FF'],
    ['Taller de script lettering', '#FFA83D'],
    ['Taller de mangas', '#FF9E7A'],
    ['Diseño de productos gráficos', '#FF7A78'],
    ['Representación de proyectos de edificación', '#5B84E8'],
    ['Confección y publicación de páginas web', '#A9E58A'],
    ['Desarrollo de productos audiovisuales multimedia interactivos', '#A9E58A'],
    ['Animación 2D y 3D aplicada a productos audiovisuales', '#5B84E8'],
    ['Diseño artístico de videojuegos', '#5B84E8'],
];

$sql = ["ALTER TABLE estudio ADD COLUMN empresa VARCHAR(20) NOT NULL DEFAULT 'EFP'"];

foreach ($nuevos as $i => [$nombre, $color]) {
    // Sin área, para que no cambie cómo se reconocen al importar los que ya había.
    // FROM (SELECT 1) permite el WHERE NOT EXISTS igual en MariaDB y en SQLite.
    $sql[] = sprintf(
        "INSERT INTO estudio (nombre, tipo, area, color, orden, activo, empresa)
         SELECT %s, 'CURSO', NULL, '%s', %d, 1, 'ESTACION' FROM (SELECT 1 AS uno) t
         WHERE NOT EXISTS (SELECT 1 FROM estudio WHERE nombre = %s)",
        $quote($nombre), $color, 40 + $i, $quote($nombre)
    );
    $sql[] = sprintf(
        'INSERT INTO estudio_centro (estudio_id, centro_id)
         SELECT e.id, c.id FROM estudio e, centro c
         WHERE e.nombre = %s AND c.id = 1
           AND NOT EXISTS (SELECT 1 FROM estudio_centro x WHERE x.estudio_id = e.id AND x.centro_id = c.id)',
        $quote($nombre)
    );
}

$nombres = array_map($quote, [...$existentes, ...array_column($nuevos, 0)]);
$sql[] = sprintf(
    "UPDATE estudio SET empresa = 'ESTACION' WHERE id IN (%s) OR nombre IN (%s)",
    implode(', ', $estacion), implode(', ', $nombres)
);

return $sql;
