<?php

declare(strict_types=1);

/**
 * Servidor de desarrollo: hace lo mismo que el .htaccess de producción
 * (envía /api a la aplicación PHP y el resto a la web de React).
 *
 *   php -S localhost:8080 -t frontend/dist backend/bin/dev-router.php
 */

$root = dirname(__DIR__, 2);
$webRoot = $root . '/frontend/dist';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api')) {
    require $root . '/backend/public/index.php';
    return true;
}

$file = realpath($webRoot . $path);
if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, realpath($webRoot))) {
    return false; // lo sirve el servidor integrado
}

if (!is_file($webRoot . '/index.html')) {
    http_response_code(500);
    echo 'Falta frontend/dist. Genéralo con "npm run build" dentro de frontend/.';
    return true;
}

header('Content-Type: text/html; charset=utf-8');
readfile($webRoot . '/index.html');

return true;
