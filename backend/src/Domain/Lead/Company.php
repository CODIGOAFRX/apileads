<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

/**
 * A qué empresa del grupo va un lead. Cada estudio es de una de las dos; el lead
 * es de Estación Diseño si alguno de sus estudios de interés lo es y, si no
 * (o si aún no tiene estudio), de Grupo EFP.
 */
enum Company: string
{
    case ESTACION = 'ESTACION';
    case EFP = 'EFP';

    /** @param list<array{empresa?: ?string}> $studies */
    public static function ofStudies(array $studies): self
    {
        foreach ($studies as $study) {
            if (($study['empresa'] ?? null) === self::ESTACION->value) {
                return self::ESTACION;
            }
        }

        return self::EFP;
    }

    public function label(): string
    {
        return match ($this) {
            self::ESTACION => 'Estación Diseño',
            self::EFP => 'Grupo EFP',
        };
    }

    /** Para el catálogo que usa la web: id => nombre. */
    public static function catalog(): array
    {
        $list = [];
        foreach (self::cases() as $company) {
            $list[$company->value] = $company->label();
        }

        return $list;
    }
}
