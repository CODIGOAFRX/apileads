<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

use Leads\Domain\Shared\Text;

/** Canal por el que llegó el lead. */
enum LeadOrigin: string
{
    case WEB = 'WEB';
    case FACEBOOK = 'FACEBOOK';
    case INSTAGRAM = 'INSTAGRAM';
    case GOOGLE = 'GOOGLE';
    case TIKTOK = 'TIKTOK';
    case FERIA = 'FERIA';
    case TELEFONO = 'TELEFONO';
    case MANUAL = 'MANUAL';
    case OTRO = 'OTRO';

    private const SYNONYMS = [
        'WEB' => ['web', 'website', 'pagina web', 'formulario web', 'formulario', 'wordpress', 'landing', 'organic', 'organico', 'seo'],
        'FACEBOOK' => ['facebook', 'fb', 'meta', 'facebook ads', 'meta ads', 'lead ads'],
        'INSTAGRAM' => ['instagram', 'ig', 'insta'],
        'GOOGLE' => ['google', 'google ads', 'adwords', 'cpc', 'gads'],
        'TIKTOK' => ['tiktok', 'tik tok'],
        'FERIA' => ['feria', 'evento', 'jornada', 'puertas abiertas', 'open day'],
        'TELEFONO' => ['telefono', 'llamada', 'phone', 'whatsapp'],
        'MANUAL' => ['manual'],
    ];

    public static function fromText(?string $value): ?self
    {
        $slug = Text::slug($value);
        if ($slug === '') {
            return null;
        }
        $direct = self::tryFrom(strtoupper(str_replace(' ', '_', $slug)));
        if ($direct !== null) {
            return $direct;
        }
        foreach (self::SYNONYMS as $origin => $synonyms) {
            if (in_array($slug, $synonyms, true)) {
                return self::from($origin);
            }
        }
        foreach (self::SYNONYMS as $origin => $synonyms) {
            foreach ($synonyms as $synonym) {
                if (strlen($synonym) > 3 && str_contains($slug, $synonym)) {
                    return self::from($origin);
                }
            }
        }

        return self::OTRO;
    }

    public function label(): string
    {
        return match ($this) {
            self::WEB => 'Web',
            self::FACEBOOK => 'Facebook',
            self::INSTAGRAM => 'Instagram',
            self::GOOGLE => 'Google',
            self::TIKTOK => 'TikTok',
            self::FERIA => 'Feria / evento',
            self::TELEFONO => 'Teléfono',
            self::MANUAL => 'Alta manual',
            self::OTRO => 'Otro',
        };
    }
}
