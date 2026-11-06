<?php

declare(strict_types=1);

namespace Leads\Core;

/**
 * Detecta valores de ejemplo (de .env.example o del Docker local) que no deben
 * llegar a producción: con el JWT_SECRET de ejemplo cualquiera podría fabricarse
 * una sesión de administración. Con APP_DEBUG=1 (local) no se comprueba nada.
 */
final class ExampleConfig
{
    /** Trozos de texto que solo aparecen en los valores de ejemplo. */
    private const MARKERS = [
        'JWT_SECRET' => ['pon-aqui', 'no-usar-en-produccion'],
        'ADMIN_PASSWORD' => ['cambia-esta', 'AdminLocal2026'],
        'DB_PASSWORD' => ['cambia-esta'],
        'MAIL_PASSWORD' => ['contraseña-del-buzon'],
    ];

    /** @return list<string> variables que siguen con su valor de ejemplo */
    public static function leftovers(): array
    {
        if (Env::bool('APP_DEBUG')) {
            return [];
        }

        $found = [];
        foreach (self::MARKERS as $key => $markers) {
            $value = (string) Env::get($key, '');
            $decoded = base64_decode($value, true);
            foreach ($markers as $marker) {
                if (str_contains($value, $marker) || ($decoded !== false && str_contains($decoded, $marker))) {
                    $found[] = $key;
                    break;
                }
            }
        }

        return $found;
    }

    public static function assertJwtSecretIsReal(): void
    {
        if (in_array('JWT_SECRET', self::leftovers(), true)) {
            throw new \RuntimeException('JWT_SECRET tiene el valor de ejemplo: genera uno nuevo en backend/.env', 500);
        }
    }
}
