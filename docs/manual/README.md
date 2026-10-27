# Fuente del manual del equipo comercial

De aquí sale [`docs/Manual-Leads-Equipo-Comercial.pdf`](../Manual-Leads-Equipo-Comercial.pdf): el texto en HTML, los estilos, las capturas y los scripts que las sacan de una demo local con datos inventados.

| Fichero | Qué es |
| --- | --- |
| `manual.src.html` | El texto del manual, con atajos (ver abajo). |
| `estilo.css`, `cover.html` | Diseño de las páginas y la portada (colores y fuentes de la app). |
| `capturas/` | Las capturas que usa el manual. |
| `build.cjs` | Monta `manual.html` y genera `portada.pdf` y `cuerpo.pdf` con Chromium. |
| `paginas.py` | Lee de `cuerpo.pdf` en qué página empieza cada capítulo, para el índice. |
| `post.py` | Une portada y cuerpo, dibuja la línea de la marca y el pie, y pone los marcadores. |
| `revisar.py`, `hoja.py` | Hojas de miniaturas para revisar el PDF o un grupo de capturas. |
| `lib.cjs`, `api.cjs`, `pw.cjs` | Utilidades de Playwright y de la API para las capturas. |
| `prepare.cjs`, `preparar-bd.py`, `buzon.cjs` | Preparan la demo para los ejemplos del manual (Elena, Nerea, Pablo, Marta…). |
| `fase-a.cjs` … `fase-g.cjs` | Sacan las capturas, en orden: cada fase cambia la demo (llamadas, correos, importaciones…). |
| `recortes.py` | Parte en dos la captura de una fila (capítulo 4). |
| `ficheros/` | Los CSV de ejemplo que se importan en el capítulo 11. |

Hace falta Node, Python con `pymupdf` y `Pillow`, y Playwright con Chromium (`npm i -D playwright` aquí o `playwright-cli` global). Si el Chromium de esa versión de Playwright no está descargado, indica uno con `CHROMIUM_PATH`.

## Cambiar solo el texto

Todos los comandos se ejecutan desde `docs/manual`:

```bash
node build.cjs && python paginas.py && node build.cjs paginas.json && python paginas.py && python post.py ../Manual-Leads-Equipo-Comercial.pdf
```

Se monta dos veces: la primera calcula en qué página empieza cada capítulo y la segunda escribe esos números en el índice. `paginas.py` tiene que dar lo mismo las dos veces. Después revisa el resultado con `python revisar.py ../Manual-Leads-Equipo-Comercial.pdf` (deja miniaturas en `rev/`).

### Atajos en `manual.src.html`

| Atajo | Sale |
| --- | --- |
| `{{img:nombre\|pie\|clases}}` | La captura `capturas/nombre.png`. Clases: `w30`…`w90` (ancho), `noborder`, `center`. |
| `[[n:3]]` | Círculo rosa con número. |
| `[[k:1]]` | Tecla. |
| `[[i:wa]]`, `[[i:mail]]`, `[[i:ok]]`, `[[i:eye]]`, `[[i:call]]`… | Botón redondo de la app. |
| `[[b:icono\|Texto]]`, `[[bs:icono\|Texto]]` | Botón de la app (blanco o negro). El icono puede ir vacío. |
| `[[t:pendientes]]`, `[[t:no-contestan\|no contestan]]` | Pestaña con su color. |
| `[[s:NUEVO\|Nuevo]]` | Etiqueta de estado. |
| `[[c:ESTACION]]`, `[[c:EFP]]` | Etiqueta de empresa. |
| `{{pg:07}}` | Página del capítulo 7 (solo en el índice). |

Cada capítulo es un `<section class="chap">` con su `<h1>`: los `<h1>` dan los marcadores del PDF y las páginas del índice. Si añades o quitas un capítulo, actualiza también la lista `NOMBRES` de `post.py`.

## Volver a sacar las capturas

Las capturas se sacan de una demo nueva en SQLite con datos inventados. **No uses nunca una base con datos reales.** Desde la raíz del repositorio, con `frontend/dist` compilado (`npm run build` en `frontend/`):

```bash
export DB_DSN="sqlite:$PWD/docs/manual/manual.sqlite" APP_DEBUG=1 MAIL_TRANSPORT=demo JWT_SECRET=bG9jYWwtc29sby1wYXJhLWRlc2Fycm9sbG8tbm8tdXNhci1lbi1wcm9kdWNjaW9u ADMIN_EMAIL=admin@estaciondiseno.es ADMIN_PASSWORD=AdminLocal2026
php backend/bin/demo.php
php -S localhost:8093 -t frontend/dist backend/bin/dev-router.php
```

`MAIL_TRANSPORT=demo` hace que los correos se apunten en `backend/storage/logs/correos.log` sin salir y sin el aviso de «modo pruebas». Con el servidor en marcha, desde `docs/manual`:

```bash
node prepare.cjs && python preparar-bd.py manual.sqlite
node buzon.cjs firma && node buzon.cjs on
node fase-a.cjs && python recortes.py
node fase-b.cjs && node fase-c.cjs && node fase-d.cjs && node fase-e.cjs && node fase-f.cjs && node fase-g.cjs
```

- Las fases cambian la demo, así que van en orden y solo funcionan una vez. Para repetir una, guarda antes una copia de `manual.sqlite` y restáurala.
- Las horas de las capturas son las del momento en que se sacan. `preparar-bd.py` fija la entrada de Elena el 27/10/2026: cámbiala si las rehaces otro día.
- `buzon.cjs on` pone a Marta un buzón falso para que se vea en «De». `fase-b.cjs` lo quita justo antes de enviar y lo vuelve a poner después. Nunca envíes correos con el buzón puesto.
- Las capturas llevan los números rosas (`mark` en `lib.cjs`). Revisa las de cada fase con `python hoja.py capturas/_hoja.png nombre1 nombre2…`.
- `fase-g.cjs` corrige en la captura un fallo de la app: en Ajustes → Correos, el estudio elegido sale con el texto blanco sobre blanco.
