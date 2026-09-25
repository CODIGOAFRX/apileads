<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

use Leads\Domain\Shared\Text;

/**
 * De dónde viene el lead. Al darlo de alta se elige uno de los diez primeros;
 * OTRO («Sin indicar») queda solo para leads antiguos o importados sin origen.
 */
enum LeadOrigin: string
{
    case WEB = 'WEB';
    case REDES_SOCIALES = 'REDES_SOCIALES';
    case REFERENCIAS = 'REFERENCIAS';
    case ANO_PASADO = 'ANO_PASADO';
    case FERIA = 'FERIA';
    case COLEGIOS = 'COLEGIOS';
    case APRENDEMAS = 'APRENDEMAS';
    case EMAGISTER = 'EMAGISTER';
    case TUMASTER = 'TUMASTER';
    case EDUCAWEB = 'EDUCAWEB';
    case OTRO = 'OTRO';

    /**
     * Cómo puede venir escrito en un fichero (en minúsculas y sin tildes). Los
     * portales van primero: «educaweb» contiene «web» y debe quedarse en EducaWeb.
     */
    private const SYNONYMS = [
        'EDUCAWEB' => ['educaweb', 'educa web'],
        'EMAGISTER' => ['emagister', 'e magister'],
        'TUMASTER' => ['tumaster', 'tu master'],
        'APRENDEMAS' => ['aprendemas', 'aprende mas'],
        'REDES_SOCIALES' => ['redes', 'redes sociales', 'rrss', 'rr ss', 'social', 'facebook', 'fb', 'meta', 'facebook ads',
            'meta ads', 'lead ads', 'instagram', 'ig', 'insta', 'tiktok', 'tik tok', 'linkedin', 'youtube', 'twitter', 'x'],
        'REFERENCIAS' => ['referencia', 'referencias', 'referido', 'recomendacion', 'recomendado', 'boca a boca', 'amigo', 'familiar', 'alumno'],
        'ANO_PASADO' => ['ano pasado', 'curso pasado', 'ano anterior', 'curso anterior', 'lead antiguo'],
        'FERIA' => ['feria', 'ferias', 'evento', 'salon', 'jornada', 'puertas abiertas', 'open day'],
        'COLEGIOS' => ['colegio', 'colegios', 'instituto', 'institutos', 'ies', 'charla', 'centro educativo', 'orientador'],
        'WEB' => ['web', 'website', 'pagina web', 'formulario web', 'formulario', 'wordpress', 'landing', 'organic', 'organico',
            'seo', 'google', 'google ads', 'adwords', 'cpc', 'gads'],
    ];

    /** Los que se pueden elegir, en el orden en que se muestran. */
    public static function selectable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $o): bool => $o !== self::OTRO));
    }

    public function isSelectable(): bool
    {
        return $this !== self::OTRO;
    }

    /** Lo reconoce en un texto libre (columna de un fichero); OTRO si no sabe cuál es, null si está vacío. */
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
        // «Campaña Instagram septiembre», «Stand feria Granada»…
        $words = ' ' . $slug . ' ';
        foreach (self::SYNONYMS as $origin => $synonyms) {
            foreach ($synonyms as $synonym) {
                if (strlen($synonym) > 3 ? str_contains($slug, $synonym) : str_contains($words, " {$synonym} ")) {
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
            self::REDES_SOCIALES => 'Redes sociales',
            self::REFERENCIAS => 'Referencias',
            self::ANO_PASADO => 'Año pasado',
            self::FERIA => 'Feria',
            self::COLEGIOS => 'Colegios',
            self::APRENDEMAS => 'AprendeMás',
            self::EMAGISTER => 'Emagister',
            self::TUMASTER => 'TuMaster',
            self::EDUCAWEB => 'EducaWeb',
            self::OTRO => 'Sin indicar',
        };
    }

    /** Para el catálogo que usa la web. */
    public static function catalog(): array
    {
        return array_map(static fn (self $o): array => ['id' => $o->value, 'label' => $o->label(), 'seleccionable' => $o->isSelectable()], self::cases());
    }
}
