<?php

declare(strict_types=1);

namespace Leads\Domain\Shared;

/**
 * Normalización de texto para búsquedas y emparejamientos:
 * minúsculas, sin tildes y con espacios simples.
 */
final class Text
{
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'º' => 'o', 'ª' => 'a',
    ];

    public static function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = mb_strtolower(self::clean($value), 'UTF-8');
        $value = strtr($value, self::ACCENTS);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** Normaliza y deja solo letras, números y espacios (para comparar cabeceras o cursos). */
    public static function slug(?string $value): string
    {
        $value = self::normalize($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /** Limpia espacios raros, caracteres de control y BOM que traen las exportaciones. */
    public static function clean(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = str_replace(["\u{FEFF}", "\u{200B}", "\u{00A0}"], ['', '', ' '], $value);
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return trim($value);
    }

    public static function nullIfEmpty(?string $value): ?string
    {
        $value = self::clean($value);
        return $value === '' ? null : $value;
    }

    /** Escapa un término para usarlo dentro de LIKE con ESCAPE '!'. */
    public static function likeContains(string $term): string
    {
        return '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    public static function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    /** Pone en mayúscula la primera letra de cada palabra si el texto llega todo en mayúsculas o minúsculas. */
    public static function properName(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', self::clean($value)));
        if ($value === '') {
            return '';
        }
        $isAllUpper = mb_strtoupper($value, 'UTF-8') === $value;
        $isAllLower = mb_strtolower($value, 'UTF-8') === $value;
        if (!$isAllUpper && !$isAllLower) {
            return $value;
        }

        return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}
