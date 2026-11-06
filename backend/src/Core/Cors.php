<?php

declare(strict_types=1);

namespace Leads\Core;

final class Cors
{
    public static function allowedOrigin(?string $origin, array $allowed): ?string
    {
        if ($origin === null || trim($origin) === '') {
            return null;
        }

        return in_array($origin, $allowed, true) ? $origin : null;
    }

    public static function apply(): void
    {
        $allowed = Env::list('CORS_ALLOWED_ORIGINS') ?: ['http://localhost:5173', 'http://127.0.0.1:5173'];
        $origin = self::allowedOrigin($_SERVER['HTTP_ORIGIN'] ?? null, $allowed);
        if ($origin !== null) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Expose-Headers: Content-Disposition');
    }
}
