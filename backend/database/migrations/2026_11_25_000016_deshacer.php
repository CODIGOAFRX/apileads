<?php

/**
 * Deshacer y rehacer: cada cambio de un lead hecho desde la aplicación guarda cómo
 * estaba antes y cómo quedó después (ver UndoService). Cada usuario tiene su propia
 * pila; deshecho_en marca los que se han deshecho y se pueden rehacer.
 */
return [
    'CREATE TABLE deshacer (
        id {PK},
        usuario_id {FK} NOT NULL,
        lead_id {FK} NOT NULL,
        descripcion VARCHAR(255) NOT NULL DEFAULT \'\',
        antes {JSON} NOT NULL,
        despues {JSON} NOT NULL,
        deshecho_en DATETIME NULL,
        creado_en DATETIME NOT NULL,
        FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE,
        FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE
    ) {TABLE}',
    'CREATE INDEX idx_deshacer_usuario ON deshacer (usuario_id, id)',
];
