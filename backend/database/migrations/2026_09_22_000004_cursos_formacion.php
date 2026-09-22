<?php

/**
 * Cursos de formación que llegan por los formularios de Meta, además de los
 * estudios de diseño. Cada uno con su código como alias: así se reconoce
 * aunque el nombre del formulario traiga otros códigos delante o erratas.
 * No duplica nada si ya existe un estudio o un alias con ese nombre.
 */
$now = gmdate('Y-m-d H:i:s');

$cursos = [
    // [nombre, color, alias (normalizados: minúsculas, sin tildes, "_" como espacio)]
    ['Dirección por objetivos y gestión del desempeño', '#5B84E8', ['adgd073po']],
    ['Competencias para el desarrollo de los recursos humanos de la empresa', '#FFA83D', ['fcoo12']],
    ['Gestión comercial de ventas', '#8A86FF', ['comt0411']],
    ['Instagram para tu negocio', '#FF79F5', ['comm0023']],
    ['Gestión de quejas y reclamaciones en materia de consumo', '#FF9E7A', ['mf0245 3']],
    ['TikTok para empresas', '#8CE5FF', ['ctrd0016', 'tik tok para empresas']],
    ['WhatsApp Business para mejorar la comunicación con los clientes', '#A9E58A', ['ctrd0018']],
    ['Técnicas de reclutamiento y selección de personal', '#D06EFA', ['adgd260po']],
    ['Gestión de recepción', '#FFFF82', ['hota0009']],
    ['Modelos de negocio en la economía circular', '#FF7A78', ['seag02']],
    ['Inglés profesional para actividades comerciales', '#00FF81', ['mf1002 2']],
    ['Transformación digital de la empresa', '#FFA83D', ['adgd352po']],
];

$sql = [];
foreach ($cursos as $i => [$nombre, $color, $aliases]) {
    // FROM (SELECT 1) permite el WHERE NOT EXISTS igual en MariaDB y en SQLite.
    $sql[] = sprintf(
        "INSERT INTO estudio (nombre, tipo, area, color, orden, activo)
         SELECT '%s', 'CURSO', NULL, '%s', %d, 1 FROM (SELECT 1 AS uno) t
         WHERE NOT EXISTS (SELECT 1 FROM estudio WHERE nombre = '%s')",
        $nombre, $color, 20 + $i, $nombre
    );
    foreach ($aliases as $alias) {
        $sql[] = sprintf(
            "INSERT INTO estudio_alias (estudio_id, alias, creado_en)
             SELECT e.id, '%s', '%s' FROM estudio e
             WHERE e.id = (SELECT MIN(id) FROM estudio WHERE nombre = '%s')
               AND NOT EXISTS (SELECT 1 FROM estudio_alias WHERE alias = '%s')",
            $alias, $now, $nombre, $alias
        );
    }
}

return $sql;
