<?php

/**
 * Correo propio de cada comercial y dossier adjunto a las plantillas.
 *
 *  - cuenta_correo: buzón de cada usuario (SMTP para enviar, IMAP para dejar copia en
 *    Enviados). La contraseña se guarda cifrada (ver Leads\Infrastructure\Security\SecretBox).
 *  - adjunto: ficheros guardados en la base de datos, así viajan con las copias de seguridad
 *    y no dependen de permisos de carpetas en el servidor.
 *  - Textos de la comercial y dossier 26-27 de Grado Diseño Gráfico, Grado Diseño Moda,
 *    CFGS Ilustración y Máster Ilustración (database/correos). Sustituyen lo que hubiera.
 *  - La genérica y la de «sin estudio» pasan al mismo estilo solo si nadie las había cambiado.
 */
$now = gmdate('Y-m-d H:i:s');
$dir = dirname(__DIR__) . '/correos/';
$quote = static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'";
$read = static fn (string $file): string => trim((string) file_get_contents($dir . $file));

$sql = [
    'CREATE TABLE adjunto (
        id {PK},
        nombre VARCHAR(190) NOT NULL,
        tipo VARCHAR(100) NOT NULL,
        tamano INT NOT NULL,
        contenido {BLOB} NOT NULL,
        creado_en DATETIME NOT NULL
    ) {TABLE}',
    'ALTER TABLE plantilla_correo ADD COLUMN adjunto_id {FK} NULL',

    'CREATE TABLE cuenta_correo (
        id {PK},
        usuario_id {FK} NOT NULL UNIQUE,
        email VARCHAR(190) NOT NULL,
        usuario VARCHAR(190) NOT NULL,
        password_cifrada TEXT NOT NULL,
        smtp_host VARCHAR(190) NOT NULL,
        smtp_puerto INT NOT NULL,
        smtp_cifrado VARCHAR(10) NOT NULL,
        imap_host VARCHAR(190) NULL,
        imap_puerto INT NULL,
        guardar_enviados {BOOL} NOT NULL DEFAULT 0,
        carpeta_enviados VARCHAR(190) NULL,
        verificar_certificado {BOOL} NOT NULL DEFAULT 1,
        actualizado_en DATETIME NOT NULL,
        FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE
    ) {TABLE}',

    // Desde qué buzón salió cada correo.
    'ALTER TABLE envio_correo ADD COLUMN remitente VARCHAR(190) NULL',
];

$estudios = [
    // [estudio, asunto, texto, dossier, nombre con el que le llega al lead]
    [2, 'Información: Grado en Diseño Gráfico · Estación Diseño', 'grado-diseno-grafico.html', 'dossier-grado-diseno-grafico.pdf', 'Dossier Grado Diseño Gráfico 26-27.pdf'],
    [1, 'Información: Grado en Diseño de Moda · Estación Diseño', 'grado-diseno-moda.html', 'dossier-grado-diseno-moda.pdf', 'Dossier Grado Diseño de Moda 26-27.pdf'],
    [4, 'Información: CFGS de Ilustración · Estación Diseño', 'cfgs-ilustracion.html', 'dossier-cfgs-ilustracion.pdf', 'Dossier Ciclo Ilustración 26-27.pdf'],
    [5, 'Información: Máster Oficial de Ilustración · Estación Diseño', 'master-ilustracion.html', 'dossier-master-ilustracion.pdf', 'Dossier Máster Oficial Ilustración 26-27.pdf'],
];

foreach ($estudios as [$estudioId, $asunto, $texto, $dossier, $nombre]) {
    $pdf = (string) file_get_contents($dir . $dossier);
    $sql[] = sprintf('DELETE FROM plantilla_correo WHERE estudio_id = %d', $estudioId);
    // X'…' (hexadecimal) vale igual en MariaDB y en SQLite para datos binarios.
    $sql[] = sprintf(
        "INSERT INTO adjunto (nombre, tipo, tamano, contenido, creado_en) VALUES (%s, 'application/pdf', %d, X'%s', '%s')",
        $quote($nombre), strlen($pdf), bin2hex($pdf), $now
    );
    // Solo si el estudio sigue existiendo; el adjunto recién creado es el de mayor id.
    $sql[] = sprintf(
        "INSERT INTO plantilla_correo (estudio_id, clave, asunto, cuerpo_html, adjunto_id, actualizado_en)
         SELECT e.id, NULL, %s, %s, (SELECT MAX(id) FROM adjunto), '%s' FROM estudio e WHERE e.id = %d",
        $quote($asunto), $quote($read($texto)), $now, $estudioId
    );
}
$sql[] = 'DELETE FROM adjunto WHERE id NOT IN (SELECT adjunto_id FROM plantilla_correo WHERE adjunto_id IS NOT NULL)';

// Textos originales de 2026_09_17_000002 y 2026_09_22_000003: si siguen igual, se sustituyen.
$genericaOriginal = '<p>Hola {{nombre}}:</p>'
    . '<p>Gracias por tu interés en {{estudios}} de Estación Diseño. Te adjuntamos la información que nos pediste.</p>'
    . '{{bloques_estudios}}'
    . '<p>Si tienes cualquier duda, responde a este correo y te ayudamos.</p>'
    . '<p>Un saludo,<br>{{comercial}}<br>Estación Diseño · Escuela Superior de Diseño</p>';
$sinEstudioOriginal = '<p>Hola {{nombre}}:</p>'
    . '<p>Gracias por tu interés en Estación Diseño.</p>'
    . '<p>Para enviarte la información que mejor encaje contigo, ¿nos cuentas qué te gustaría estudiar? '
    . 'Puedes responder a este mismo correo y te ayudamos con cualquier duda.</p>'
    . '<p>Un saludo,<br>{{comercial}}<br>Estación Diseño · Escuela Superior de Diseño</p>';

$sql[] = sprintf(
    "UPDATE plantilla_correo SET cuerpo_html = %s, actualizado_en = '%s' WHERE estudio_id IS NULL AND clave IS NULL AND cuerpo_html = %s",
    $quote($read('generica.html')), $now, $quote($genericaOriginal)
);
$sql[] = sprintf(
    "UPDATE plantilla_correo SET cuerpo_html = %s, actualizado_en = '%s' WHERE estudio_id IS NULL AND clave = 'sin-estudio' AND cuerpo_html = %s",
    $quote($read('sin-estudio.html')), $now, $quote($sinEstudioOriginal)
);

return $sql;
