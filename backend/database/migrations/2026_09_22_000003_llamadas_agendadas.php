<?php

/**
 * Llamadas agendadas (el lead pide que le llamen un día y una hora concretos)
 * y plantilla de correo propia para quien no ha dicho qué quiere estudiar.
 */
$now = gmdate('Y-m-d H:i:s');

// Texto provisional: se cambia desde Ajustes → Correos → «Sin estudio».
$cuerpo = '<p>Hola {{nombre}}:</p>'
    . '<p>Gracias por tu interés en Estación Diseño.</p>'
    . '<p>Para enviarte la información que mejor encaje contigo, ¿nos cuentas qué te gustaría estudiar? '
    . 'Puedes responder a este mismo correo y te ayudamos con cualquier duda.</p>'
    . '<p>Un saludo,<br>{{comercial}}<br>Estación Diseño · Escuela Superior de Diseño</p>';

return [
    'ALTER TABLE leads ADD COLUMN llamada_agendada DATETIME NULL',
    'CREATE INDEX idx_lead_agendada ON leads (llamada_agendada)',

    // Plantillas especiales sin estudio asociado. NULL = la genérica de siempre.
    'ALTER TABLE plantilla_correo ADD COLUMN clave VARCHAR(30) NULL',
    sprintf(
        "INSERT INTO plantilla_correo (estudio_id, clave, asunto, cuerpo_html, actualizado_en) VALUES (NULL, 'sin-estudio', '%s', '%s', '%s')",
        'Información sobre Estación Diseño',
        $cuerpo,
        $now
    ),
];
