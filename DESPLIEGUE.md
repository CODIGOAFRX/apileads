# Despliegue en Plesk

Igual que la Biblioteca: se sube la carpeta del proyecto por FTP/SFTP y la raíz web apunta a
`frontend/dist`. La API PHP queda **fuera** de la raíz web y se llega a ella a través de
`frontend/dist/backend.php`.

```
/httpdocs/apileads/            ⇄  carpeta local apileads/
├── frontend/dist/     ← RAÍZ WEB (Plesk → Hosting → Raíz del documento)
│   ├── .htaccess      (envía /api a backend.php y el resto a index.html)
│   ├── .user.ini      (límite de subida 12 MB para importar)
│   └── backend.php
└── backend/
    ├── .env           ← configuración de PRODUCCIÓN (nunca se sube la local)
    ├── vendor/        ← dependencias PHP
    └── storage/logs/  ← debe poder escribir PHP
```

## Primera instalación

1. **Subdominio** en Plesk, p. ej. `leads.estaciondiseno.es`, con PHP 8.1 o superior (FPM) y SSL (Let's Encrypt).
   Extensiones necesarias: `pdo_mysql`, `mbstring`, `zip`, `xml`, `gd` (vienen activas por defecto en Plesk).
2. **Base de datos** MariaDB: Plesk → Bases de datos → Añadir (`apileads`, usuario `apileads`).
3. **Buzón** para enviar la información, p. ej. `admisiones@estaciondiseno.es` (Plesk → Correo).
4. En local, copia `backend/.env.example` a `backend/.env` **solo para producción** y rellénalo:
   base de datos, `JWT_SECRET` (genera uno largo), `ADMIN_EMAIL`/`ADMIN_PASSWORD` y el buzón (`MAIL_*`).
5. Sube por FTP la carpeta a `/httpdocs/apileads` (sin `frontend/node_modules`, `frontend/src`, `.git`).
6. Plesk → Hosting y DNS → **Raíz del documento**: `httpdocs/apileads/frontend/dist`.
7. Crear tablas: Plesk → **Tareas programadas** → Añadir tarea → *Ejecutar un script PHP* →
   `httpdocs/apileads/backend/bin/migrate.php` → **Ejecutar ahora**. Crea las tablas, los 10 estudios
   con sus colores, el centro de Granada, la plantilla genérica y el administrador.
   (Después se puede desactivar la tarea; solo hace falta volver a ejecutarla cuando haya migraciones nuevas.
   Es seguro repetirla.)
8. Entra con el administrador, crea las cuentas de las comerciales (Ajustes → Usuarios) y revisa las
   plantillas de correo de cada estudio y su dossier (Ajustes → Correos).
9. **Buzón de cada comercial**: Ajustes → Usuarios → icono de correo. Los datos salen de Plesk → Correo →
   el buzón → «Configuración del cliente de correo» → «Configuración manual» (servidor `estaciondiseno.es`,
   SMTP 465, IMAP 993) más la contraseña del buzón. Pulsa **Probar conexión** antes de guardar. Si da error
   de certificado «no de confianza», desmarca «Comprobar el certificado». La contraseña se guarda cifrada con
   `MAIL_ACCOUNTS_KEY` (o `JWT_SECRET`): si cambias esa clave, hay que volver a escribirlas.
   La aplicación envía como mucho un correo cada 72 segundos (`MAIL_SEGUNDOS_ENTRE_ENVIOS`): si Plesk tiene otro
   límite de envíos por hora, ajústalo (3600 / límite).
10. **Actividad** (menú superior, solo administración): lo que ha hecho cada comercial cada día.

> **No hace falta ninguna tarea programada para "las 9:00"**: la vuelta de los que no contestaron se
> calcula en cada consulta, y el listado se refresca solo cada minuto.

## Actualizaciones

1. Si cambió el frontend: `cd frontend && npm run build` y sube `frontend/dist`.
2. Si cambió el backend: sube `backend/src` (y `backend/database` si hay migraciones nuevas; entonces
   ejecuta de nuevo la tarea del paso 7).
   Los dossieres PDF van dentro de la base de datos (tabla `adjunto`): la migración de correos los carga
   desde `backend/database/correos/`, así que esa carpeta tiene que subirse antes de ejecutarla.
3. Si cambiaron las dependencias PHP: `composer install --no-dev --optimize-autoloader` y sube `backend/vendor`.

## Nunca se sube

`backend/.env` local, `backend/storage/logs/*`, `frontend/node_modules`, `.git`, `_tmp`.

## Comprobaciones tras desplegar

- `https://leads.estaciondiseno.es/api/health` → `{"ok":true}`
- `https://leads.estaciondiseno.es/.env` no debe descargar nada (el `.env` está fuera de la raíz web).
- Importar la plantilla de ejemplo y enviar un correo de prueba a tu dirección.

## Seguridad

- El `.env` contiene contraseñas: permisos 600 si el FTP lo permite.
- Como en la Biblioteca, mejor SFTP que FTP plano.
- Las sesiones duran 12 h (`JWT_TTL_SECONDS`). Cambiar `JWT_SECRET` cierra todas las sesiones.
- Tras 5 intentos fallidos de acceso, esa cuenta queda bloqueada 15 minutos desde esa IP.
