<?php

/**
 * Esquema inicial. Tokens {PK}, {FK}, {TABLE}, {BOOL} y {JSON}: ver Leads\Infrastructure\Persistence\Dialect.
 * Todas las fechas se guardan en UTC.
 */
return [
    'CREATE TABLE usuario (
        id {PK},
        nombre VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        rol VARCHAR(20) NOT NULL,
        activo {BOOL} NOT NULL DEFAULT 1,
        ultimo_acceso DATETIME NULL,
        creado_en DATETIME NOT NULL
    ) {TABLE}',

    'CREATE TABLE login_intento (
        id {PK},
        clave VARCHAR(190) NOT NULL,
        creado_en DATETIME NOT NULL
    ) {TABLE}',
    'CREATE INDEX idx_login_intento_clave ON login_intento (clave, creado_en)',

    'CREATE TABLE centro (
        id {PK},
        nombre VARCHAR(120) NOT NULL,
        ciudad VARCHAR(120) NULL,
        provincia VARCHAR(120) NULL,
        activo {BOOL} NOT NULL DEFAULT 1
    ) {TABLE}',

    'CREATE TABLE estudio (
        id {PK},
        nombre VARCHAR(160) NOT NULL,
        tipo VARCHAR(20) NOT NULL,
        area VARCHAR(40) NULL,
        color VARCHAR(7) NOT NULL DEFAULT \'#00FF81\',
        orden INT NOT NULL DEFAULT 0,
        activo {BOOL} NOT NULL DEFAULT 1
    ) {TABLE}',

    'CREATE TABLE estudio_centro (
        estudio_id {FK} NOT NULL,
        centro_id {FK} NOT NULL,
        PRIMARY KEY (estudio_id, centro_id),
        FOREIGN KEY (estudio_id) REFERENCES estudio (id) ON DELETE CASCADE,
        FOREIGN KEY (centro_id) REFERENCES centro (id) ON DELETE CASCADE
    ) {TABLE}',

    'CREATE TABLE estudio_alias (
        id {PK},
        estudio_id {FK} NOT NULL,
        alias VARCHAR(190) NOT NULL UNIQUE,
        creado_en DATETIME NOT NULL,
        FOREIGN KEY (estudio_id) REFERENCES estudio (id) ON DELETE CASCADE
    ) {TABLE}',

    'CREATE TABLE importacion (
        id {PK},
        usuario_id {FK} NULL,
        archivo VARCHAR(255) NOT NULL,
        origen VARCHAR(40) NULL,
        total_filas INT NOT NULL DEFAULT 0,
        creados INT NOT NULL DEFAULT 0,
        fusionados INT NOT NULL DEFAULT 0,
        omitidos INT NOT NULL DEFAULT 0,
        incidencias {JSON} NULL,
        creado_en DATETIME NOT NULL,
        FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE SET NULL
    ) {TABLE}',

    'CREATE TABLE leads (
        id {PK},
        nombre VARCHAR(160) NOT NULL DEFAULT \'\',
        apellidos VARCHAR(160) NOT NULL DEFAULT \'\',
        email VARCHAR(190) NULL,
        email_normalizado VARCHAR(190) NULL,
        telefono VARCHAR(40) NULL,
        telefono_digitos VARCHAR(20) NULL,
        ubicacion VARCHAR(190) NULL,
        interes_texto VARCHAR(255) NULL,
        origen VARCHAR(40) NOT NULL DEFAULT \'OTRO\',
        campana VARCHAR(190) NULL,
        comentarios TEXT NULL,
        datos_extra {JSON} NULL,
        estado VARCHAR(20) NOT NULL DEFAULT \'NUEVO\',
        intentos_sin_respuesta INT NOT NULL DEFAULT 0,
        proxima_llamada DATETIME NULL,
        ultima_llamada DATETIME NULL,
        cita_en DATETIME NULL,
        motivo_descarte VARCHAR(255) NULL,
        centro_id {FK} NULL,
        bloqueado_por {FK} NULL,
        bloqueado_hasta DATETIME NULL,
        nombre_busqueda VARCHAR(330) NOT NULL DEFAULT \'\',
        texto_busqueda VARCHAR(700) NOT NULL DEFAULT \'\',
        fecha_origen DATETIME NULL,
        ultima_entrada DATETIME NOT NULL,
        importacion_id {FK} NULL,
        creado_por {FK} NULL,
        creado_en DATETIME NOT NULL,
        actualizado_en DATETIME NOT NULL,
        FOREIGN KEY (centro_id) REFERENCES centro (id) ON DELETE SET NULL,
        FOREIGN KEY (bloqueado_por) REFERENCES usuario (id) ON DELETE SET NULL,
        FOREIGN KEY (importacion_id) REFERENCES importacion (id) ON DELETE SET NULL,
        FOREIGN KEY (creado_por) REFERENCES usuario (id) ON DELETE SET NULL
    ) {TABLE}',
    'CREATE INDEX idx_lead_bandeja ON leads (estado, proxima_llamada)',
    'CREATE INDEX idx_lead_entrada ON leads (ultima_entrada)',
    'CREATE INDEX idx_lead_email ON leads (email_normalizado)',
    'CREATE INDEX idx_lead_telefono ON leads (telefono_digitos)',
    'CREATE INDEX idx_lead_origen ON leads (origen)',

    'CREATE TABLE lead_estudio (
        lead_id {FK} NOT NULL,
        estudio_id {FK} NOT NULL,
        PRIMARY KEY (lead_id, estudio_id),
        FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE,
        FOREIGN KEY (estudio_id) REFERENCES estudio (id) ON DELETE CASCADE
    ) {TABLE}',
    'CREATE INDEX idx_lead_estudio_estudio ON lead_estudio (estudio_id)',

    'CREATE TABLE lead_actividad (
        id {PK},
        lead_id {FK} NOT NULL,
        usuario_id {FK} NULL,
        tipo VARCHAR(30) NOT NULL,
        resumen VARCHAR(255) NOT NULL DEFAULT \'\',
        detalle {JSON} NULL,
        creado_en DATETIME NOT NULL,
        FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE SET NULL
    ) {TABLE}',
    'CREATE INDEX idx_actividad_lead ON lead_actividad (lead_id, creado_en)',
    'CREATE INDEX idx_actividad_fecha ON lead_actividad (creado_en)',

    'CREATE TABLE plantilla_correo (
        id {PK},
        estudio_id {FK} NULL UNIQUE,
        asunto VARCHAR(190) NOT NULL,
        cuerpo_html TEXT NOT NULL,
        actualizado_en DATETIME NOT NULL,
        FOREIGN KEY (estudio_id) REFERENCES estudio (id) ON DELETE CASCADE
    ) {TABLE}',

    'CREATE TABLE envio_correo (
        id {PK},
        lead_id {FK} NOT NULL,
        usuario_id {FK} NULL,
        destinatario VARCHAR(190) NOT NULL,
        asunto VARCHAR(190) NOT NULL,
        estado VARCHAR(20) NOT NULL,
        error VARCHAR(500) NULL,
        creado_en DATETIME NOT NULL,
        FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE SET NULL
    ) {TABLE}',
];
