<?php

/**
 * Centro de Granada, los 11 estudios del manual de marca (con su color) y la
 * plantilla de correo genérica. Los alias ayudan a emparejar lo que llega de
 * formularios y anuncios.
 */
$now = gmdate('Y-m-d H:i:s');

$estudios = [
    // [id, nombre, tipo, area, color]
    [1, 'Grado Diseño Moda', 'GRADO', 'MODA', '#FF79F5'],
    [2, 'Grado Diseño Gráfico', 'GRADO', 'GRAFICO', '#FF7A78'],
    [3, 'CFGS Gráfica Audiovisual', 'CFGS', 'AUDIOVISUAL', '#8A86FF'],
    [4, 'CFGS Ilustración', 'CFGS', 'ILUSTRACION', '#FFFF82'],
    [5, 'Máster Ilustración', 'MASTER', 'ILUSTRACION', '#8CE5FF'],
    [6, 'Máster Profesional Moda', 'MASTER', 'MODA', '#D06EFA'],
    [7, 'Máster Profesional Gráfico', 'MASTER', 'GRAFICO', '#FFA83D'],
    [8, 'CFGS Gráfica Interactiva', 'CFGS', 'INTERACTIVA', '#A9E58A'],
    [9, 'CFGS Cómic', 'CFGS', 'COMIC', '#FF9E7A'],
    [10, 'Máster Profesional Modelado y Animación 3D', 'MASTER', 'MODELADO_3D', '#5B84E8'],
];

$aliases = [
    1 => ['grado diseno moda', 'grado en diseno de moda', 'grado moda', 'diseno de moda'],
    2 => ['grado diseno grafico', 'grado en diseno grafico', 'grado grafico'],
    3 => ['cfgs grafica audiovisual', 'grafica audiovisual', 'ciclo grafica audiovisual'],
    4 => ['cfgs ilustracion', 'ciclo ilustracion', 'ciclo superior de ilustracion'],
    5 => ['master ilustracion', 'master en ilustracion'],
    6 => ['master profesional moda', 'master moda', 'master en moda'],
    7 => ['master profesional grafico', 'master grafico', 'master en diseno grafico'],
    8 => ['cfgs grafica interactiva', 'grafica interactiva', 'ciclo grafica interactiva'],
    9 => ['cfgs comic', 'ciclo comic', 'comic'],
    10 => ['master profesional modelado y animacion 3d', 'master 3d', 'modelado y animacion 3d', 'animacion 3d'],
];

$sql = [
    "INSERT INTO centro (id, nombre, ciudad, provincia, activo) VALUES (1, 'Estación Diseño Granada', 'Granada', 'Granada', 1)",
];

foreach ($estudios as $orden => [$id, $nombre, $tipo, $area, $color]) {
    $sql[] = sprintf(
        "INSERT INTO estudio (id, nombre, tipo, area, color, orden, activo) VALUES (%d, '%s', '%s', '%s', '%s', %d, 1)",
        $id, $nombre, $tipo, $area, $color, $orden + 1
    );
    $sql[] = sprintf('INSERT INTO estudio_centro (estudio_id, centro_id) VALUES (%d, 1)', $id);
}

foreach ($aliases as $estudioId => $lista) {
    foreach ($lista as $alias) {
        $sql[] = sprintf("INSERT INTO estudio_alias (estudio_id, alias, creado_en) VALUES (%d, '%s', '%s')", $estudioId, $alias, $now);
    }
}

$cuerpo = '<p>Hola {{nombre}}:</p>'
    . '<p>Gracias por tu interés en {{estudios}} de Estación Diseño. Te adjuntamos la información que nos pediste.</p>'
    . '{{bloques_estudios}}'
    . '<p>Si tienes cualquier duda, responde a este correo y te ayudamos.</p>'
    . '<p>Un saludo,<br>{{comercial}}<br>Estación Diseño · Escuela Superior de Diseño</p>';

$sql[] = sprintf(
    "INSERT INTO plantilla_correo (estudio_id, asunto, cuerpo_html, actualizado_en) VALUES (NULL, '%s', '%s', '%s')",
    'Información sobre {{estudios}} · Estación Diseño',
    $cuerpo,
    $now
);

return $sql;
