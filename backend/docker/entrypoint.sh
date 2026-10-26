#!/bin/sh
# Arranque del contenedor: dependencias, base de datos y servidor de desarrollo.
set -e
cd /app/backend

if [ ! -d vendor ]; then
  echo "→ Instalando dependencias PHP…"
  composer install --no-interaction --prefer-dist
fi

comprobar_bd() {
  php -r '
    try {
      new PDO(getenv("DB_DSN"), getenv("DB_USER"), getenv("DB_PASSWORD"));
      exit(0);
    } catch (Throwable $e) {
      fwrite(STDERR, $e->getMessage() . PHP_EOL);
      exit(1);
    }
  '
}

echo "→ Esperando a la base de datos (DB_DSN=${DB_DSN}, usuario=${DB_USER})…"
intento=1
while ! comprobar_bd 2>/tmp/bd-error; do
  if [ "$intento" -ge 20 ]; then
    echo "" >&2
    echo "No se pudo conectar con la base de datos. Lo que responde el servidor:" >&2
    cat /tmp/bd-error >&2
    echo "" >&2
    echo "Pistas:" >&2
    echo "  · 'Access denied'  → la base de datos se creó antes con otra contraseña: para todo y vacíala con 'docker compose down -v'." >&2
    echo "  · 'Connection refused' o 'getaddrinfo' → el contenedor de MariaDB no está levantado: revisa 'docker compose ps'." >&2
    echo "  · 'could not find driver' → falta la extensión pdo_mysql: reconstruye con 'docker compose build --no-cache app'." >&2
    exit 1
  fi
  # Cada 5 intentos se muestra el motivo, por si no es solo cuestión de esperar.
  if [ $((intento % 5)) -eq 0 ]; then
    echo "   intento ${intento}: $(tr -d '\n' < /tmp/bd-error)" >&2
  fi
  intento=$((intento + 1))
  sleep 2
done

php bin/migrate.php

# Acceso rápido en local: usuario "admin", contraseña "admin" (solo con APP_DEBUG=1).
if [ "$APP_DEBUG" = "1" ]; then
  php bin/admin-local.php
fi

if [ "$DEMO" = "1" ]; then
  php bin/demo.php
fi

echo "→ Leads en http://localhost:8080"
exec php -S 0.0.0.0:8080 -t /app/frontend/dist /app/backend/bin/dev-router.php
