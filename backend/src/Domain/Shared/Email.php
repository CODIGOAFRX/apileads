<?php

declare(strict_types=1);

namespace Leads\Domain\Shared;

final class Email
{
    /** Limpia el correo (espacios, mailto:, mayúsculas). Devuelve null si no hay nada. */
    public static function clean(?string $raw): ?string
    {
        $value = Text::clean($raw);
        $value = (string) preg_replace('/^mailto:/i', '', $value);
        $value = (string) preg_replace('/\s+/', '', $value);
        $value = trim($value, " \t\n\r\0\x0B<>\"'.,;");

        return $value === '' ? null : mb_strtolower($value, 'UTF-8');
    }

    public static function isValid(?string $email): bool
    {
        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
