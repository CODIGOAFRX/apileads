<?php

/**
 * Las comerciales pueden crear estudios (p. ej. cursos cortos nuevos) cuando no
 * existen. Se guarda quién y cuándo para que administración pueda revisarlos.
 */
return [
    'ALTER TABLE estudio ADD COLUMN creado_por {FK} NULL',
    'ALTER TABLE estudio ADD COLUMN creado_en DATETIME NULL',
];
