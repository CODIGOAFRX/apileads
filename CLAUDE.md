# Leads · Estación Diseño (apileads)

Herramienta del equipo comercial de Estación Diseño para llamar, seguir y convertir leads.
Backend PHP 8.1 sin framework + MariaDB (SQLite en pruebas) en `backend/`; SPA React 19 + Vite en `frontend/`.
Se despliega en un Plesk compartido (ver `DESPLIEGUE.md`). Todo el texto de la app y de los comentarios va en
español de España.

- Arquitectura: `Router → Controller → Service → Repository`, con las reglas puras en `backend/src/Domain`.
- Pruebas backend: `cd backend && vendor/bin/phpunit` y `vendor/bin/phpstan` (o dentro de Docker, ver README).
- Frontend: `cd frontend && npm.cmd run check` (ESLint + pruebas + compilación). `npm run build` regenera `frontend/dist`.
- Vista local: `docker compose up --build` → http://localhost:8080 (usuario `admin` / `admin`).

## Skills de este proyecto

**Proyecto** (`.claude/skills/`, `.claude/agents/`, `.claude/settings.local.json`; `.claude/` no se sube a git):

| Herramienta | Cuándo usarla |
|---|---|
| `impeccable` | Para cualquier trabajo de UI. Se configura con `/impeccable init` (crea PRODUCT.md y DESIGN.md). Antes de dar algo por terminado, `/impeccable audit`; como pasada final, `/impeccable polish`. Su hook revisa la UI tras cada edición. |
| `modern-web-guidance` | Consúltala antes de escribir HTML, CSS o JS de interfaz (diálogos, popovers, formularios, rendimiento). Trae también `chrome-extensions`, que aquí no se usa. |
| `emil-design-eng` | Para animar con criterio: pulsación, hover, transiciones, timing de modales y avisos. |
| `review-animations` | Para revisar el movimiento cuando ya está hecho. |
| `break-ui` | Para probar pantallas con datos feos (nombres largos, cero leads, cifras enormes, emoji). |
| `untangle-codebase`, `prune-tests` (Caldo AI) | Para desenredar código que ha crecido sin orden y quitar pruebas que no protegen nada. |
| `scroll-world` (plugin) | Experiencias de scroll con imagen y vídeo generados. **Usa servicios de pago (Monid o Higgsfield): no generar nada sin preguntar.** En esta app casi nunca tiene sentido. |
| Subagentes `php-pro`, `sql-pro`, `react-specialist`, `code-reviewer`, `accessibility-tester` (VoltAgent) e `impeccable-*` | Para delegar backend PHP, consultas y migraciones, componentes React, revisión de código y auditoría de accesibilidad. |

**Usuario** (`~/.claude/skills/` y plugins de usuario):

| Herramienta | Cuándo usarla |
|---|---|
| `taste-skill` (plugin) | Dirección de arte y reglas anti «hecho por IA» al diseñar pantallas nuevas. |
| `ui-ux-pro-max` (+ `design`, `design-system`, `ui-styling`, `brand`, `banner-design`, `slides`) | Paletas, tipografías, guías de UX y presets de animación antes de decidir. |
| `web-design-guidelines` | Auditar la UI contra las Web Interface Guidelines de Vercel. |
| `playwright-cli` | Abrir la app en un navegador real, hacer capturas y probar flujos (`playwright-cli.cmd`). |
| `find-skills` | Buscar e instalar skills nuevas cuando falte algo. |
| `claude-mem` (plugin) | Memoria entre sesiones. Necesita `bun` instalado para funcionar del todo. |
| `cloudflare` (plugin) | No aplica a este proyecto (se despliega en Plesk). |

No instalados a propósito: n8n MCP (el proyecto no usa n8n) y LightRAG (no responde sobre documentos).
Estilo de referencia: ninguno. Se sigue el lenguaje visual propio de la app.
