<?php

/**
 * Un correo de información por estudio: cada envío guarda de qué estudio iba,
 * para saber qué información ha recibido ya el lead y frenar el doble clic
 * estudio a estudio (ver MailService). Los envíos anteriores quedan sin estudio.
 */
return [
    'ALTER TABLE envio_correo ADD COLUMN estudio_id {FK} NULL',
    'CREATE INDEX idx_envio_correo_estudio ON envio_correo (lead_id, estudio_id)',
];
