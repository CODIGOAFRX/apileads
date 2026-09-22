# Frontend · Leads

React 19 + Vite. Sin dependencias de interfaz: iconos en `src/components/Icon.jsx` y estilos en
`src/styles/index.css` (tokens de la Biblioteca: tinta, papel, paleta por titulación, "la línea").

```
src/
  api/          cliente HTTP (fetch) y endpoints
  auth/         login y sesión
  components/   piezas comunes (Modal, Drawer, avisos, chips de estudio…)
  features/
    leads/      listado, buscador, menú de llamada, ficha, alta/edición, correo y WhatsApp
    import/     asistente de importación
    settings/   cuenta, usuarios, estudios y centros, plantillas de correo
  lib/          formatos de fecha (hora de Madrid) y definiciones del flujo
```

- `npm run dev` — desarrollo (envía `/api` a http://localhost:8080).
- `npm run build` — genera `dist/`, que es lo que se sube al servidor (incluye `.htaccess`, `.user.ini` y `backend.php`).
- `npm test` — pruebas de `src/lib` con el runner nativo de Node.
