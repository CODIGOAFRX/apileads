<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Domain\Lead\LeadOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LeadOriginTest extends TestCase
{
    public function testSeEligeUnoDeLosSeleccionables(): void
    {
        self::assertSame(
            ['Web', 'Redes sociales', 'Referencias', 'Año pasado', 'Feria', 'Colegios', 'AprendeMás', 'Emagister', 'TuMaster', 'EducaWeb', 'Visita al centro', 'Otros'],
            array_map(static fn (LeadOrigin $o): string => $o->label(), LeadOrigin::selectable()),
        );
        self::assertFalse(LeadOrigin::OTRO->isSelectable());
        self::assertSame(
            ['AprendeMás', 'Emagister', 'TuMaster', 'EducaWeb'],
            array_values(array_map(static fn (LeadOrigin $o): string => $o->label(), array_filter(LeadOrigin::cases(), static fn (LeadOrigin $o): bool => $o->isLeadPortal()))),
        );
        self::assertSame(['id' => 'OTRO', 'label' => 'Sin indicar', 'seleccionable' => false], LeadOrigin::catalog()[12]);
    }

    public static function texts(): array
    {
        return [
            'valor exacto' => ['ANO_PASADO', LeadOrigin::ANO_PASADO],
            'con tilde' => ['Año pasado', LeadOrigin::ANO_PASADO],
            'portal con tilde' => ['AprendeMás', LeadOrigin::APRENDEMAS],
            'contiene «web» pero es EducaWeb' => ['educaweb.com', LeadOrigin::EDUCAWEB],
            'EducaWeb separado' => ['Educa Web', LeadOrigin::EDUCAWEB],
            'TuMaster' => ['tu master', LeadOrigin::TUMASTER],
            'Emagister' => ['EMAGISTER', LeadOrigin::EMAGISTER],
            'antiguo Facebook' => ['FACEBOOK', LeadOrigin::REDES_SOCIALES],
            'abreviatura' => ['IG', LeadOrigin::REDES_SOCIALES],
            'dentro de una frase' => ['Campaña Instagram septiembre', LeadOrigin::REDES_SOCIALES],
            'Meta' => ['meta ads', LeadOrigin::REDES_SOCIALES],
            'Google va a web' => ['Google Ads', LeadOrigin::WEB],
            'formulario web' => ['Formulario de la web', LeadOrigin::WEB],
            'colegio' => ['Charla IES Padre Suárez', LeadOrigin::COLEGIOS],
            'recomendación' => ['Recomendación de una alumna', LeadOrigin::REFERENCIAS],
            'feria' => ['Stand feria Granada', LeadOrigin::FERIA],
            'jornada' => ['Jornada de puertas abiertas', LeadOrigin::FERIA],
            'visita al centro' => ['Visita al centro', LeadOrigin::VISITA_CENTRO],
            'vino en persona' => ['Vino en persona a secretaría', LeadOrigin::VISITA_CENTRO],
            'visita a una feria es feria' => ['Visita a la feria de Sevilla', LeadOrigin::FERIA],
            'otros' => ['Otros', LeadOrigin::OTROS],
            'otro sigue siendo sin indicar' => ['OTRO', LeadOrigin::OTRO],
            'no se sabe' => ['teléfono', LeadOrigin::OTRO],
            'abreviatura dentro de otra palabra no cuenta' => ['figura', LeadOrigin::OTRO],
        ];
    }

    #[DataProvider('texts')]
    public function testReconoceElOrigenEnUnFichero(string $text, LeadOrigin $expected): void
    {
        self::assertSame($expected, LeadOrigin::fromText($text));
    }

    public function testVacioEsNull(): void
    {
        self::assertNull(LeadOrigin::fromText(null));
        self::assertNull(LeadOrigin::fromText('  '));
    }
}
