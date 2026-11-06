<?php

/**
 * Firma de correo de cada usuario (HTML). Se añade al final de cada correo que
 * envía y la rellena administración en Ajustes → Usuarios.
 */
return [
    'ALTER TABLE usuario ADD COLUMN firma_html TEXT NULL',
];
