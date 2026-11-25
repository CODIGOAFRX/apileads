<?php

/**
 * Textos de la comercial y dossier 26-27 de los másteres profesionales (titulación
 * propia): Diseño Gráfico Digital e IA, Diseño de Moda e Ilustración Avanzada.
 * Ilustración Avanzada no existía como estudio (solo el Máster Oficial): se crea,
 * con alias para que las importaciones lo reconozcan. Sustituyen lo que hubiera.
 */
$now = gmdate('Y-m-d H:i:s');
$dir = dirname(__DIR__) . '/correos/';
$quote = static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'";
$ilustracion = 'Máster Profesional Ilustración Avanzada';

$sql = [
    // FROM (SELECT 1) permite el WHERE NOT EXISTS igual en MariaDB y en SQLite.
    sprintf(
        "INSERT INTO estudio (nombre, tipo, area, color, orden, activo)
         SELECT %s, 'MASTER', 'ILUSTRACION', '#FFD166', 11, 1 FROM (SELECT 1 AS uno) t
         WHERE NOT EXISTS (SELECT 1 FROM estudio WHERE nombre = %s)",
        $quote($ilustracion), $quote($ilustracion)
    ),
    sprintf(
        'INSERT INTO estudio_centro (estudio_id, centro_id)
         SELECT e.id, c.id FROM estudio e, centro c
         WHERE e.nombre = %s AND c.id = 1
           AND NOT EXISTS (SELECT 1 FROM estudio_centro x WHERE x.estudio_id = e.id AND x.centro_id = c.id)',
        $quote($ilustracion)
    ),
];

// Cómo se identifica cada estudio en SQL.
$byId = static fn (int $id): string => (string) $id;
$byName = static fn (string $name): string => sprintf('(SELECT MIN(id) FROM estudio WHERE nombre = %s)', $quote($name));

$aliases = [
    // [estudio, alias normalizados: minúsculas, sin tildes]
    [$byName($ilustracion), ['master profesional ilustracion avanzada', 'master ilustracion avanzada', 'ilustracion avanzada', 'master profesional ilustracion']],
    [$byId(7), ['master diseno grafico digital e ia', 'master diseno grafico digital', 'master grafico digital', 'master profesional de diseno grafico']],
    [$byId(6), ['master profesional de moda', 'master diseno de moda', 'master profesional de diseno de moda']],
];
foreach ($aliases as [$study, $list]) {
    foreach ($list as $alias) {
        $sql[] = sprintf(
            "INSERT INTO estudio_alias (estudio_id, alias, creado_en)
             SELECT e.id, '%s', '%s' FROM estudio e
             WHERE e.id = %s AND NOT EXISTS (SELECT 1 FROM estudio_alias WHERE alias = '%s')",
            $alias, $now, $study, $alias
        );
    }
}

$templates = [
    // [estudio, asunto, texto, dossier, nombre con el que le llega al lead]
    [$byId(7), 'Información: Máster de Diseño Gráfico Digital e IA · Estación Diseño', 'master-profesional-grafico.html', 'dossier-master-profesional-grafico.pdf', 'Dossier Máster Diseño Gráfico Digital e IA 26-27.pdf'],
    [$byId(6), 'Información: Máster de Diseño de Moda · Estación Diseño', 'master-profesional-moda.html', 'dossier-master-profesional-moda.pdf', 'Dossier Máster Profesional Moda 26-27.pdf'],
    [$byName($ilustracion), 'Información: Máster de Ilustración Avanzada · Estación Diseño', 'master-profesional-ilustracion.html', 'dossier-master-profesional-ilustracion.pdf', 'Dossier Máster Profesional Ilustración 26-27.pdf'],
];
foreach ($templates as [$study, $subject, $text, $dossier, $name]) {
    $pdf = (string) file_get_contents($dir . $dossier);
    $sql[] = sprintf('DELETE FROM plantilla_correo WHERE estudio_id = %s', $study);
    // X'…' (hexadecimal) vale igual en MariaDB y en SQLite para datos binarios.
    $sql[] = sprintf(
        "INSERT INTO adjunto (nombre, tipo, tamano, contenido, creado_en) VALUES (%s, 'application/pdf', %d, X'%s', '%s')",
        $quote($name), strlen($pdf), bin2hex($pdf), $now
    );
    $sql[] = sprintf(
        "INSERT INTO plantilla_correo (estudio_id, clave, asunto, cuerpo_html, adjunto_id, actualizado_en)
         SELECT e.id, NULL, %s, %s, (SELECT MAX(id) FROM adjunto), '%s' FROM estudio e WHERE e.id = %s",
        $quote($subject), $quote(trim((string) file_get_contents($dir . $text))), $now, $study
    );
}
$sql[] = 'DELETE FROM adjunto WHERE id NOT IN (SELECT adjunto_id FROM plantilla_correo WHERE adjunto_id IS NOT NULL)';

return $sql;
