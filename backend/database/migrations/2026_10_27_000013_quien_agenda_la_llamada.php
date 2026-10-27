<?php

/**
 * Quién agendó cada llamada: a esa persona le salta el aviso a la hora acordada
 * (ver LeadService::reminders). Se vacía al hacer la llamada o cerrar el lead.
 */
return [
    'ALTER TABLE leads ADD COLUMN agendada_por {FK} NULL',
];
