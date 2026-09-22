# Imagen solo para desarrollo local. En producción (Plesk) se usa el PHP del servidor.
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libzip-dev libpng-dev \
    && docker-php-ext-install pdo_mysql zip gd \
    && printf 'upload_max_filesize=12M\npost_max_size=14M\nmemory_limit=512M\n' > /usr/local/etc/php/conf.d/apileads.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# El código se monta desde el equipo (docker-compose), así los cambios se ven al momento.
WORKDIR /app
ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 8080
CMD ["/usr/local/bin/entrypoint.sh"]
