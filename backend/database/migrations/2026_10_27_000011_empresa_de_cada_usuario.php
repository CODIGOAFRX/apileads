<?php

/**
 * Qué leads ve cada usuario: los de Estación Diseño, los de Grupo EFP o los de las
 * dos (NULL, como hasta ahora). Lo decide administración en Ajustes → Usuarios.
 * Ver Leads\Domain\Lead\Company::ofUser.
 */
return [
    'ALTER TABLE usuario ADD COLUMN empresa VARCHAR(20) NULL',
];
