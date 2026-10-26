#!/usr/bin/env bash
# Deja en _plesk/ todo lo que se sube al servidor de la escuela (Plesk), sacado del Docker local:
#
#   ./preparar-plesk.sh            (con "docker compose up" en marcha; en Windows, desde Git Bash)
#
#   _plesk/apileads-plesk.zip   → la aplicación: se sube y se extrae en la carpeta del dominio
#   _plesk/apileads.sql         → la base de datos (Plesk → Bases de datos → Importar volcado)
#   _plesk/env-produccion       → la configuración con las contraseñas (ya va dentro del zip como backend/.env)
#
# La base de datos se limpia en una COPIA (bin/limpiar-pruebas.php): la del Docker no se toca.
# env-produccion se genera solo la primera vez; si se borra, salen claves nuevas y hay que volver a subirlo todo.
set -euo pipefail
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1   # Git Bash: que no convierta /app/... en rutas de Windows

OUT=_plesk
COPIA=apileads_plesk
ROOT_PW=root-local
DB="docker compose exec -T db"
APP="docker compose exec -T app"
EN_COPIA=(-e "DB_DSN=mysql:host=db;port=3306;dbname=${COPIA};charset=utf8mb4" -e DB_USER=root -e "DB_PASSWORD=${ROOT_PW}")

mkdir -p "$OUT"

echo "→ Pruebas del backend"
[ "${SIN_PRUEBAS:-0}" = 1 ] || $APP sh -c 'cd backend && vendor/bin/phpunit --no-progress' | tail -3

echo "→ Configuración de producción"
if [ ! -f "$OUT/env-produccion" ]; then
  $APP php backend/bin/preparar-plesk.php env > "$OUT/env-produccion.tmp"
  mv "$OUT/env-produccion.tmp" "$OUT/env-produccion"
  echo "   creada $OUT/env-produccion (claves nuevas)"
else
  echo "   se reutiliza $OUT/env-produccion"
fi

echo "→ Copia de la base de datos y limpieza de pruebas"
$DB sh -c "mariadb -uroot -p${ROOT_PW} -e 'DROP DATABASE IF EXISTS ${COPIA}; CREATE DATABASE ${COPIA} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' \
  && mariadb-dump -uroot -p${ROOT_PW} --single-transaction --hex-blob apileads | mariadb -uroot -p${ROOT_PW} ${COPIA}"
docker compose exec -T "${EN_COPIA[@]}" app php backend/bin/migrate.php
docker compose exec -T "${EN_COPIA[@]}" app php backend/bin/limpiar-pruebas.php --aplicar
docker compose exec -T "${EN_COPIA[@]}" app php backend/bin/preparar-plesk.php datos "/app/$OUT/env-produccion"

echo "→ Volcado $OUT/apileads.sql"
# Sin CREATE DATABASE ni USE: se importa en la base de datos que se cree en Plesk, se llame como se llame.
# La primera línea de mariadb-dump moderno ("sandbox mode") la rechaza MySQL: se quita.
$DB mariadb-dump -uroot -p${ROOT_PW} --single-transaction --hex-blob --skip-dump-date --no-tablespaces ${COPIA} \
  | sed '/enable the sandbox mode/d' > "$OUT/apileads.sql"
$DB mariadb -uroot -p${ROOT_PW} -e "DROP DATABASE ${COPIA}"

echo "→ Compilación del frontend"
(cd frontend && npm run build --silent)

echo "→ Carpeta de la aplicación"
rm -rf "$OUT/apileads"
mkdir -p "$OUT/apileads/frontend" "$OUT/apileads/backend/storage/logs"
cp -r frontend/dist "$OUT/apileads/frontend/dist"
cp -r backend/bin backend/database backend/public backend/src backend/vendor \
      backend/composer.json backend/composer.lock backend/.htaccess "$OUT/apileads/backend/"
cp backend/storage/logs/.gitkeep "$OUT/apileads/backend/storage/logs/"
cp "$OUT/env-produccion" "$OUT/apileads/backend/.env"
# Solo para local o peligrosos en el servidor.
rm -f "$OUT/apileads/backend/bin/"{admin-local,demo,dev-router,limpiar-pruebas,preparar-plesk}.php \
      "$OUT/apileads/backend/database/docker-init.sql"
# Sin PHPUnit ni PHPStan, y con el autoload optimizado.
$APP sh -c "cd /app/$OUT/apileads/backend && composer install --no-dev --optimize-autoloader --no-interaction --quiet"

echo "→ Zip"
$APP php backend/bin/preparar-plesk.php zip "/app/$OUT/apileads" "/app/$OUT/apileads-plesk.zip"

echo ""
echo "Listo. Sube $OUT/apileads-plesk.zip y $OUT/apileads.sql siguiendo DESPLIEGUE.md (Subida a Plesk)."
ls -lh "$OUT/apileads-plesk.zip" "$OUT/apileads.sql"
