<?php

/**
 * - registro: lo que hace cada usuario y no forma parte del historial de un lead
 *   (entrar, abrir una ficha o una llamada, pulsar el teléfono, importar, exportar,
 *   cambios de ajustes). Los resultados de llamadas, correos, WhatsApp, notas…
 *   siguen en lead_actividad; la pantalla «Actividad» junta las dos.
 * - limite_envio: hora del último correo enviado por la aplicación, para no pasar
 *   de uno cada 72 segundos (el servidor admite 50 por hora) sin montar una cola.
 */
return [
    'CREATE TABLE registro (
        id {PK},
        usuario_id {FK} NULL,
        lead_id {FK} NULL,
        tipo VARCHAR(30) NOT NULL,
        resumen VARCHAR(255) NOT NULL DEFAULT \'\',
        detalle {JSON} NULL,
        ip VARCHAR(45) NULL,
        creado_en DATETIME NOT NULL,
        FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE SET NULL,
        FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL
    ) {TABLE}',
    'CREATE INDEX idx_registro_fecha ON registro (creado_en)',
    'CREATE INDEX idx_actividad_usuario ON lead_actividad (usuario_id, creado_en)',

    'CREATE TABLE limite_envio (
        clave VARCHAR(30) NOT NULL PRIMARY KEY,
        ultimo DATETIME NOT NULL
    ) {TABLE}',
    "INSERT INTO limite_envio (clave, ultimo) VALUES ('correo', '2000-01-01 00:00:00')",
];
