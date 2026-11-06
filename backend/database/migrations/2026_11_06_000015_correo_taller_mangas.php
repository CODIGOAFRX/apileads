<?php

/**
 * Texto de la comercial y dossier del curso corto «Taller de mangas» con Javier
 * Martín Galán (12 y 13 de diciembre). Sustituye lo que hubiera.
 */
$now = gmdate('Y-m-d H:i:s');
$dir = dirname(__DIR__) . '/correos/';
$quote = static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'";
$study = sprintf('(SELECT MIN(id) FROM estudio WHERE nombre = %s)', $quote('Taller de mangas'));
$pdf = (string) file_get_contents($dir . 'dossier-curso-taller-mangas.pdf');

return [
    sprintf('DELETE FROM plantilla_correo WHERE estudio_id = %s', $study),
    // X'…' (hexadecimal) vale igual en MariaDB y en SQLite para datos binarios.
    sprintf(
        "INSERT INTO adjunto (nombre, tipo, tamano, contenido, creado_en) VALUES (%s, 'application/pdf', %d, X'%s', '%s')",
        $quote('Taller de Mangas Javier Martín Galán.pdf'), strlen($pdf), bin2hex($pdf), $now
    ),
    sprintf(
        "INSERT INTO plantilla_correo (estudio_id, clave, asunto, cuerpo_html, adjunto_id, actualizado_en)
         SELECT e.id, NULL, %s, %s, (SELECT MAX(id) FROM adjunto), '%s' FROM estudio e WHERE e.id = %s",
        $quote('Taller de Mangas con Javier Martín Galán – 12 y 13 de diciembre'),
        $quote(trim((string) file_get_contents($dir . 'curso-taller-mangas.html'))),
        $now,
        $study
    ),
    'DELETE FROM adjunto WHERE id NOT IN (SELECT adjunto_id FROM plantilla_correo WHERE adjunto_id IS NOT NULL)',
];
