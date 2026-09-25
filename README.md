# Leads · Estación Diseño

Herramienta del equipo comercial para gestionar las solicitudes de información (leads) que llegan
de la web, Facebook, Instagram, ferias… Misma estética y arquitectura que la
[Biblioteca](https://biblioteca.estaciondiseno.es/).

- **backend/** — API en PHP 8.1+ sin framework (Router → Controller → Service → Repository), MariaDB, PHPUnit.
- **frontend/** — React 19 + Vite, sin librerías de UI (sistema de diseño propio en `src/styles/index.css`).
- **DESPLIEGUE.md** — cómo subirlo a Plesk.

## Qué hace

| Función | Dónde está la lógica |
|---|---|
| Pestañas **Pendientes / Agendadas / No contestan / Interesados / Matriculados / Descartados / Todos**, ordenadas por fecha de entrada | `Domain/Lead/Inbox.php`, `LeadRepository::search` |
| Botón de **llamada** con 4 resultados (teclas 1 a 4) y nota opcional; **WhatsApp** y **correo** a mano en el mismo menú | `Domain/Lead/LeadWorkflow.php`, `features/leads/CallMenu.jsx` |
| **WhatsApp** (mensaje prerrellenado) y **correo** disponibles desde el primer momento, fijos en la fila del lead | `LeadActionPolicy.php` |
| **No contesta** → se propone el correo de información y, al aceptarlo, se abre WhatsApp. El lead sale de pendientes y vuelve el siguiente día laborable a las 9:00 (hora de Madrid). Se calcula al consultar: no depende de ninguna tarea programada. Al 6.º intento se descarta solo (configurable) | `BusinessCalendar.php`, `features/leads/FollowUpModals.jsx`, `.env` |
| **Agendar otra llamada** → se elige día y hora; espera en «agendadas» y a esa hora vuelve a pendientes, el primero y en verde | `LeadWorkflow::scheduleCall`, `LeadRepository::PINNED_ORDER` |
| **Tick verde** en la fila para marcar interesado sin llamar (p. ej. si ha contestado por WhatsApp) | `LeadWorkflow::markInterested` |
| Sin teléfono → solo **enviar información por correo** | `LeadActionPolicy.php` |
| **Interesado** → pasa a interesados y se ofrece enviar el correo con la información de sus estudios. Si no sabemos qué quiere estudiar se envía el correo **«Sin estudio»** (Ajustes → Correos) | `Application/MailService.php` |
| **Dossier adjunto**: cada plantilla lleva su PDF (Grado Diseño Gráfico, Grado Diseño Moda, CFGS Ilustración y Máster Ilustración ya vienen con el texto de la comercial y el dossier 26-27). Con varios estudios se envía la genérica con el bloque y el dossier de cada uno | `MailService::compose`, `database/correos/`, Ajustes → Correos |
| **Buzón de cada comercial**: administración le asigna su correo (Ajustes → Usuarios → icono de correo, con los datos de Plesk). Sus correos salen de su dirección y se guarda copia en su carpeta de Enviados por IMAP. Botón «Probar conexión». Sin buzón, sale del general (`MAIL_*`) | `MailAccountService`, `Infrastructure/Mail/PersonalMailbox.php`, `ImapClient.php` |
| Antes de enviar, el **correo se puede retocar** (texto y asunto) solo para ese envío, p. ej. «la matrícula cierra el viernes» | `MailService::personalize`, `components/HtmlPreview.jsx` |
| **No interesado** → descartados. Nunca se borra; se puede exportar a CSV y reabrir | `LeadWorkflow::registerCall` |
| Cita agendada y matriculado (embudo completo) | `LeadWorkflow` |
| **Reserva** del lead mientras una comercial lo llama (5 min) para que no llamen dos a la vez | `LeadRepository::lock` |
| **Buscador "contiene"** sin tildes ni mayúsculas: nombre, correo, teléfono por dígitos ("454"), ciudad, varias palabras en cualquier orden. Filtros por estudio, tipo (grados/ciclos/másteres), origen y fechas | `LeadSearch.php`, `LeadRepository::buildWhere` |
| **Importación flexible** CSV/Excel: detecta codificación y separador, cabeceras de web/Facebook/Google Forms y del centro de clientes potenciales de Meta, columnas por contenido, ficheros sin cabecera, filas incompletas, fechas con am/pm; empareja estudios (también por su código dentro del nombre del formulario: «F250454AA \| COMM0023 – Instagram…») y aprende alias; nunca se para por una fila mala | `Domain/Import/*`, `Application/ImportService.php` |
| **Cursos de formación** (Instagram para tu negocio, Gestión de recepción…) además de los estudios de diseño; filtro rápido «Cursos» | migración `2026_09_22_000004_cursos_formacion.php` |
| **Duplicados**: mismo correo o teléfono → se completa el lead existente (y si estaba descartado vuelve a pendientes) | `LeadService::ingest` |
| **Plantilla** descargable en Excel (con desplegables) y CSV | `Infrastructure/Spreadsheet/ImportTemplate.php` |
| Historial por lead (quién, cuándo, qué) y notas | `lead_actividad` |
| Usuarios (admin / comercial), estudios con color, centros y plantillas de correo por estudio. Administración también puede crear un estudio nuevo desde el alta de un lead o desde la importación | `features/settings/*` |

Preparado para el reparto por centros: tablas `centro` y `estudio_centro` y campo `centro_id` en cada lead.

## Verlo en local

Con **Docker Desktop** abierto, desde la carpeta del proyecto:

```bash
docker compose up --build
```

La primera vez tarda un par de minutos (descarga PHP y MariaDB). Cuando aparezca
`→ Leads en http://localhost:8080`, abre esa dirección.

Se crean solos la base de datos, los 10 estudios con sus colores y **45 leads de ejemplo**
(inventados: nombres falsos, correos `@example.com` y teléfonos que no existen).

| Entrar como | Correo | Contraseña |
|---|---|---|
| Comercial | `marta@estaciondiseno.es` | `DemoLocal2026` |
| Administración | `admin@estaciondiseno.es` | `AdminLocal2026` |

- **Parar:** `docker compose down` · **parar y vaciar la base de datos:** `docker compose down -v`
- **Si no arranca:** `docker compose logs app --tail 40` dice exactamente qué falla. Lo más habitual:
  `Access denied` (la base de datos se creó antes con otra contraseña → `docker compose down -v` y volver a levantar)
  o que MariaDB no esté levantada (`docker compose ps`). Como salida rápida, se puede arrancar sin MariaDB:
  `set DB_DSN=sqlite:/app/backend/storage/local.sqlite` y luego `docker compose up` (en PowerShell:
  `$env:DB_DSN="sqlite:/app/backend/storage/local.sqlite"`).
- **Empezar sin datos de ejemplo:** `DEMO=0 docker compose up` (en PowerShell: `$env:DEMO=0; docker compose up`)
- Los correos no se envían: se escriben en `backend/storage/logs/correos.log` (también los de las comerciales con buzón propio;
  «Probar conexión» sí conecta de verdad con su servidor, sin enviar nada).
- Para tocar el diseño con recarga automática: `cd frontend && npm install && npm run dev` → http://localhost:5173
  (envía `/api` al contenedor). Al terminar, `npm run build` regenera `frontend/dist`, que es lo que se sube al servidor.

### Sin Docker

Si tienes PHP 8.1+ instalado, basta con esto (guarda los datos en un fichero SQLite, sin MariaDB):

```bash
# Linux/macOS
DB_DSN="sqlite:$PWD/backend/storage/local.sqlite" JWT_SECRET=bG9jYWwtc29sby1wYXJhLWRlc2Fycm9sbG8tbm8tdXNhci1lbi1wcm9kdWNjaW9u ADMIN_EMAIL=admin@estaciondiseno.es ADMIN_PASSWORD=AdminLocal2026 php backend/bin/demo.php

DB_DSN="sqlite:$PWD/backend/storage/local.sqlite" JWT_SECRET=bG9jYWwtc29sby1wYXJhLWRlc2Fycm9sbG8tbm8tdXNhci1lbi1wcm9kdWNjaW9u php -S localhost:8080 -t frontend/dist backend/bin/dev-router.php
```

En Windows (PowerShell) es lo mismo con `$env:DB_DSN="sqlite:$PWD\backend\storage\local.sqlite"` y las demás
variables antes de cada comando. SQLite solo para verlo en local: en el servidor se usa MariaDB.

## Pruebas

```bash
# Backend (SQLite en memoria)
docker compose exec app sh -c 'cd backend && vendor/bin/phpunit'

# Backend contra MariaDB real (el mismo motor que el servidor)
docker compose exec -e TEST_DB_DSN="mysql:host=db;dbname=apileads_test;charset=utf8mb4" \
  -e TEST_DB_USER=apileads -e TEST_DB_PASSWORD=apileads app sh -c 'cd backend && vendor/bin/phpunit'

# Frontend (lógica pura)
cd frontend && npm test
```

Las pruebas cubren los puntos clave: flujo de llamadas y fechas de vuelta (incluido fin de semana,
festivos y cambio de hora), botones disponibles, buscador, importación (Facebook UTF-16, Excel,
Windows-1252, sin cabecera, filas de título, duplicados, errores por fila), correo (plantillas,
SMTP, inyección de cabeceras), sesión, permisos y límite de intentos de acceso.
