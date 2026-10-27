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

## Despliegue automático con Git (lead.grupoefp.es)

Cada push a `main` pasa las pruebas en GitHub Actions y, si van en verde, publica la rama **`produccion`**: la
aplicación compilada (`frontend/dist` y `backend/vendor` sin herramientas de pruebas), sin `.env` ni base de
datos. Plesk tira de esa rama:

- Plesk → lead.grupoefp.es → **Git** → `apileads.git` (`https://github.com/CODIGOAFRX/apileads.git`),
  rama `produccion`, despliegue **automático** en `/httpdocs`.
- **Raíz del documento**: `httpdocs/frontend/dist`.
- GitHub → Settings → **Webhooks**: la «URL de Webhook» que da Plesk en la configuración del repositorio
  (evento *push*). Con ella Plesk hace pull y despliega en cuanto se actualiza `produccion`; sin ella, en Plesk
  → Git → **Pull ahora** y **Desplegar ahora**.
- Una sola vez, a mano: `httpdocs/backend/.env` (el `env-produccion` de `_plesk/`) y la base de datos
  (pasos 2 y 3 de abajo). El despliegue no toca el `.env`.
- Migraciones nuevas: tarea programada con `httpdocs/backend/bin/migrate.php` (paso 7 de «Primera instalación»).

## Subida a Plesk con el paquete preparado (lo más rápido)

**En el ordenador**, con `docker compose up` en marcha, desde Git Bash:

```
./preparar-plesk.sh
```

Pasa las pruebas, hace una **copia** de la base de datos del Docker, la limpia (`bin/limpiar-pruebas.php`:
fuera el usuario `admin/admin`, el registro y los intentos de acceso; los leads quedan en pendientes),
pone al administrador la contraseña de producción, vuelve a cifrar los buzones de las comerciales con la
clave del servidor y deja en `_plesk/` (fuera de Git, lleva contraseñas):

| Fichero | Qué es |
|---|---|
| `apileads-plesk.zip` | La aplicación completa: `frontend/dist`, `backend` con `vendor` sin herramientas de pruebas y `backend/.env` de producción |
| `apileads.sql` | La base de datos: leads, usuarios (administrador y comerciales con su contraseña), estudios, plantillas y dossieres |
| `env-produccion` | La misma configuración que va en el zip. **Aquí está la contraseña del administrador** (`ADMIN_PASSWORD`) y la de la base de datos (`DB_PASSWORD`) |

`env-produccion` se crea la primera vez y se reutiliza: si se vuelve a lanzar el guion no cambian las claves.

**Lo que tiene que hacer el informático** (o tú con acceso a Plesk), una vez:

1. Crear el **subdominio**, p. ej. `leads.estaciondiseno.es`, con **PHP 8.1 o superior (FPM servido por Apache)**
   y **SSL de Let's Encrypt** (Hosting y DNS → SSL/TLS). Si el hosting es «solo nginx», el `.htaccess` no
   funciona: tiene que ser Apache (lo normal en Plesk, Apache + nginx de proxy).
2. **Base de datos** → Añadir: nombre `apileads`, usuario `apileads` y como contraseña la `DB_PASSWORD` de
   `env-produccion`. Si Plesk le pone prefijo al nombre (`escuela_apileads`) o se usa otra contraseña, se
   corrige luego en `backend/.env` (`DB_DSN` y `DB_USER`/`DB_PASSWORD`).

**Lo que haces tú** cuando esté el subdominio:

3. Plesk → **Bases de datos** → `apileads` → **Importar volcado** → `apileads.sql`.
   (También vale phpMyAdmin → Importar.)
4. Plesk → **Archivos** → la carpeta del subdominio (p. ej. `leads.estaciondiseno.es/`) → Subir
   `apileads-plesk.zip` → clic derecho → **Extraer archivos**. Quedan `frontend/` y `backend/` dentro.
   Borra el zip después.
5. Plesk → **Hosting y DNS** → Configuración de hosting → **Raíz del documento**:
   `leads.estaciondiseno.es/frontend/dist` (la carpeta del subdominio + `/frontend/dist`).
6. Si en el paso 2 cambió algo, Archivos → `backend/.env` → editar `DB_*`.
7. Comprobar (abajo, «Comprobaciones tras desplegar») y entrar como `admin@estaciondiseno.es` con la
   `ADMIN_PASSWORD` de `env-produccion`. Veronica entra con su contraseña de siempre y su buzón ya va configurado.

> No hace falta ejecutar `bin/migrate.php`: la base de datos sube con todas las migraciones hechas. Solo
> cuando haya migraciones nuevas (ver «Actualizaciones»).

## Primera instalación (a mano, con la base de datos vacía)

1. **Subdominio** en Plesk, p. ej. `leads.estaciondiseno.es`, con PHP 8.1 o superior (FPM) y SSL (Let's Encrypt).
   Las dependencias están fijadas para PHP 8.1 (`config.platform` en `composer.json`) y se prueban en 8.1, 8.3 y 8.4.
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
   Si en `backend/.env` queda algún valor de ejemplo (`JWT_SECRET`, `ADMIN_PASSWORD`, `DB_PASSWORD`, `MAIL_PASSWORD`),
   el script se niega y dice cuál: con el `JWT_SECRET` de ejemplo cualquiera podría fabricarse una sesión.
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
   **Siempre con `--no-dev`**: las herramientas de pruebas (PHPUnit, PHPStan) no van al servidor.

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
