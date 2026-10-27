<?php

declare(strict_types=1);

namespace Leads\Core;

/**
 * Lectura segura de los datos que llegan de fuera (formularios, JSON, filtros).
 * Un número o un booleano valen como texto; una lista o un objeto donde se
 * espera un texto es un error del que llama (422), nunca un fallo del servidor.
 * El texto sale siempre en UTF-8 válido y sin bytes nulos, para que MariaDB,
 * las fechas y las expresiones regulares no se atraganten.
 */
final class Input
{
    public static function text(array $data, string $key, string $default = ''): string
    {
        return self::optionalText($data, $key) ?? $default;
    }

    public static function optionalText(array $data, string $key): ?string
    {
        return self::toText($data[$key] ?? null, $key);
    }

    /** Un valor suelto (p. ej. un parámetro de la URL) como texto, o null si no viene. */
    public static function toText(mixed $value, string $name = 'dato'): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException("El dato «{$name}» no es válido");
        }
        $text = is_bool($value) ? ($value ? '1' : '') : (string) $value;

        return str_replace("\0", '', mb_scrub($text, 'UTF-8'));
    }

    /**
     * Ids positivos de una lista ([3, "5"]) o de un texto separado por comas ("3,5").
     * Lo que no sea un número entero se ignora.
     *
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        $ids = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            $id = self::id($item);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Un id positivo o null (listas, textos, decimales, cero y negativos no lo son). */
    public static function id(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
            $value = ctype_digit($value) ? (int) $value : null;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }
}
