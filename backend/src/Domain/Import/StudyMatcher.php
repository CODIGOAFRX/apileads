<?php

declare(strict_types=1);

namespace Leads\Domain\Import;

use Leads\Domain\Shared\Text;

/**
 * Empareja el texto libre de "estudio de interés" con el catálogo.
 *
 * Orden: alias aprendido → nombre exacto → código o nombre dentro del texto
 * ("F250454AA | COMM0023 – Instagram para tu negocio") → deducción por área +
 * tipo ("máster ilustración" → área ILUSTRACION y tipo MASTER). Lo que no se
 * puede decidir con seguridad se devuelve como pendiente para que lo elija una
 * persona; los códigos sueltos (expedientes, convocatorias) no se preguntan.
 */
final class StudyMatcher
{
    /** Áreas específicas primero: "gráfica audiovisual" no es "gráfico". */
    private const AREA_KEYWORDS = [
        'AUDIOVISUAL' => ['audiovisual', 'video', 'motion'],
        'INTERACTIVA' => ['interactiv', 'ux', 'ui', 'web'],
        'COMIC' => ['comic', 'manga', 'novela grafica'],
        'MODELADO_3D' => ['3d', 'modelado', 'animacion', 'animation', 'videojuego'],
        'ILUSTRACION' => ['ilustra', 'illustrat', 'dibujo'],
        'MODA' => ['moda', 'fashion', 'textil', 'patronaje'],
        'GRAFICO' => ['grafic', 'graphic'],
    ];

    private const TYPE_KEYWORDS = [
        'MASTER' => ['master', 'posgrado', 'postgrado', 'mba'],
        'GRADO' => ['grado', 'degree', 'carrera', 'universitari', 'ensenanzas artisticas superiores'],
        'CFGS' => ['cfgs', 'ciclo', 'fp', 'formacion profesional', 'tecnico superior', 'grado superior'],
    ];

    /** @var array<string, int> */
    private array $nameIndex = [];

    /**
     * @param list<array{id:int, nombre:string, tipo:string, area:?string, activo?:bool}> $studies
     * @param array<string, int> $aliases alias normalizado => id de estudio
     */
    public function __construct(private readonly array $studies, private array $aliases)
    {
        foreach ($studies as $study) {
            $this->nameIndex[Text::slug($study['nombre'])] = (int) $study['id'];
        }
    }

    /**
     * @return array{ids: list<int>, pendientes: list<array{clave:string, texto:string}>}
     */
    public function match(?string $raw): array
    {
        $ids = [];
        $pending = [];
        $embedded = false;
        foreach ($this->split($raw) as $part) {
            $key = Text::slug($part);
            if ($key === '') {
                continue;
            }
            $id = $this->aliases[$key] ?? $this->nameIndex[$key] ?? null;
            if ($id === null && ($id = $this->matchEmbedded($key)) !== null) {
                $embedded = true;
            }
            $id ??= $this->matchByAreaAndType($key);
            if ($id === null) {
                if (!self::isCode($key)) {
                    $pending[$key] = ['clave' => $key, 'texto' => $part];
                }
            } elseif ($id > 0) {
                $ids[] = $id;
            }
        }

        // En un nombre de formulario, si una parte ya lleva un estudio conocido, el
        // resto es contexto ("ARTES GRÁFICAS (F250454AA)") y no hay que preguntarlo.
        return ['ids' => array_values(array_unique($ids)), 'pendientes' => $embedded ? [] : array_values($pending)];
    }

    /**
     * Resoluciones elegidas por la persona para esta importación (clave => id; 0 = sin estudio).
     *
     * @param array<string, int> $resolutions
     */
    public function withResolutions(array $resolutions): self
    {
        $clone = clone $this;
        foreach ($resolutions as $key => $studyId) {
            $clone->aliases[Text::slug((string) $key)] = (int) $studyId;
        }

        return $clone;
    }

    /**
     * Un código de curso (lleva números) o un nombre completo del catálogo dentro
     * de un texto más largo. Los alias de una o dos palabras no se buscan así:
     * "ilustración" dentro de "ciclo de ilustración y cómic" sería adivinar.
     */
    private function matchEmbedded(string $key): ?int
    {
        $padded = ' ' . $key . ' ';
        $found = [];
        foreach ($this->aliases as $alias => $id) {
            $distinctive = preg_match('/\d/', (string) $alias) || substr_count((string) $alias, ' ') >= 2;
            if ($id > 0 && $distinctive && str_contains($padded, ' ' . $alias . ' ')) {
                $found[$id] = true;
            }
        }
        foreach ($this->nameIndex as $name => $id) {
            if (str_contains($padded, ' ' . $name . ' ')) {
                $found[$id] = true;
            }
        }

        return count($found) === 1 ? (int) array_key_first($found) : null;
    }

    /** Solo códigos: cada palabra lleva algún número ("f250457aa", "2025", "edd1"). */
    private static function isCode(string $key): bool
    {
        foreach (explode(' ', $key) as $word) {
            if (!preg_match('/\d/', $word)) {
                return false;
            }
        }

        return true;
    }

    /** @return int|null id del estudio, o null si no se puede decidir */
    private function matchByAreaAndType(string $key): ?int
    {
        $areas = $this->areasIn($key);
        $types = $this->typesIn($key);
        if (count($areas) !== 1 || count($types) > 1) {
            return null;
        }

        $candidates = array_values(array_filter(
            $this->studies,
            static fn (array $s): bool => ($s['activo'] ?? true)
                && $s['area'] === $areas[0]
                && ($types === [] || $s['tipo'] === $types[0])
        ));

        return count($candidates) === 1 ? (int) $candidates[0]['id'] : null;
    }

    /** @return list<string> */
    private function areasIn(string $key): array
    {
        $areas = $this->keywordsIn($key, self::AREA_KEYWORDS);
        // "Gráfica audiovisual", "gráfica interactiva" o "novela gráfica" no son el área de gráfico.
        if (array_intersect($areas, ['AUDIOVISUAL', 'INTERACTIVA', 'COMIC']) !== []) {
            $areas = array_values(array_diff($areas, ['GRAFICO']));
        }

        return $areas;
    }

    /** @return list<string> */
    private function typesIn(string $key): array
    {
        $types = $this->keywordsIn($key, self::TYPE_KEYWORDS);
        // "Grado superior" es un ciclo formativo, no un grado universitario.
        if (str_contains($key, 'grado superior')) {
            $types = array_values(array_diff($types, ['GRADO']));
        }

        return $types;
    }

    /**
     * @param array<string, list<string>> $dictionary
     * @return list<string>
     */
    private function keywordsIn(string $key, array $dictionary): array
    {
        $padded = ' ' . $key . ' ';
        $found = [];
        foreach ($dictionary as $value => $keywords) {
            foreach ($keywords as $keyword) {
                // Las claves cortas (ui, ux, fp, 3d) deben ser palabras completas.
                $hit = strlen($keyword) <= 3
                    ? str_contains($padded, ' ' . $keyword . ' ')
                    : str_contains($key, $keyword);
                if ($hit) {
                    $found[] = $value;
                    break;
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function split(?string $raw): array
    {
        $raw = Text::clean($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('#\s*[,;|/\n]\s*#u', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
    }
}
