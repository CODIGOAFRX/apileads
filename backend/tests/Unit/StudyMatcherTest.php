<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Domain\Import\StudyMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StudyMatcherTest extends TestCase
{
    private const STUDIES = [
        ['id' => 1, 'nombre' => 'Grado Diseño Moda', 'tipo' => 'GRADO', 'area' => 'MODA'],
        ['id' => 2, 'nombre' => 'Grado Diseño Gráfico', 'tipo' => 'GRADO', 'area' => 'GRAFICO'],
        ['id' => 3, 'nombre' => 'CFGS Gráfica Audiovisual', 'tipo' => 'CFGS', 'area' => 'AUDIOVISUAL'],
        ['id' => 4, 'nombre' => 'CFGS Ilustración', 'tipo' => 'CFGS', 'area' => 'ILUSTRACION'],
        ['id' => 5, 'nombre' => 'Máster Ilustración', 'tipo' => 'MASTER', 'area' => 'ILUSTRACION'],
        ['id' => 6, 'nombre' => 'Máster Profesional Moda', 'tipo' => 'MASTER', 'area' => 'MODA'],
        ['id' => 7, 'nombre' => 'Máster Profesional Gráfico', 'tipo' => 'MASTER', 'area' => 'GRAFICO'],
        ['id' => 8, 'nombre' => 'CFGS Gráfica Interactiva', 'tipo' => 'CFGS', 'area' => 'INTERACTIVA'],
        ['id' => 9, 'nombre' => 'CFGS Cómic', 'tipo' => 'CFGS', 'area' => 'COMIC'],
        ['id' => 10, 'nombre' => 'Máster Profesional Modelado y Animación 3D', 'tipo' => 'MASTER', 'area' => 'MODELADO_3D'],
    ];

    private function matcher(array $aliases = []): StudyMatcher
    {
        return new StudyMatcher(self::STUDIES, $aliases);
    }

    public static function recognised(): array
    {
        return [
            'nombre exacto sin tildes' => ['grado diseno grafico', [2]],
            'formato facebook' => ['máster_ilustración', [5]],
            'área + tipo' => ['Grado en Diseño de Moda', [1]],
            'ciclo de grado superior' => ['Técnico Superior en Ilustración (grado superior)', [4]],
            'gráfica audiovisual no es gráfico' => ['Gráfica audiovisual', [3]],
            'área única sin tipo' => ['cómic', [9]],
            'palabra clave 3d' => ['Animación 3D', [10]],
            'varios separados por comas' => ['Moda (grado), Máster de ilustración', [1, 5]],
        ];
    }

    #[DataProvider('recognised')]
    public function testReconoceEstudios(string $raw, array $expected): void
    {
        $result = $this->matcher()->match($raw);

        self::assertSame($expected, $result['ids']);
        self::assertSame([], $result['pendientes']);
    }

    public function testLoAmbiguoQuedaPendiente(): void
    {
        $result = $this->matcher()->match('Ilustración; Diseño gráfico; Informática');

        self::assertSame([], $result['ids']);
        self::assertSame(['ilustracion', 'diseno grafico', 'informatica'], array_column($result['pendientes'], 'clave'));
        self::assertSame('Diseño gráfico', $result['pendientes'][1]['texto']);
    }

    public function testUsaAliasAprendidosYResoluciones(): void
    {
        $matcher = $this->matcher(['ilustracion' => 5]);
        self::assertSame([5], $matcher->match('Ilustración')['ids']);

        $resolved = $matcher->withResolutions(['diseno grafico' => 2, 'informatica' => 0]);
        $result = $resolved->match('Diseño gráfico, Informática');
        self::assertSame([2], $result['ids'], 'resolución 0 = sin estudio, pero ya no se pregunta');
        self::assertSame([], $result['pendientes']);
    }

    public function testReconoceCursosDentroDelNombreDelFormulario(): void
    {
        $studies = [
            ...self::STUDIES,
            ['id' => 20, 'nombre' => 'Instagram para tu negocio', 'tipo' => 'CURSO', 'area' => null],
            ['id' => 21, 'nombre' => 'Gestión comercial de ventas', 'tipo' => 'CURSO', 'area' => null],
            ['id' => 22, 'nombre' => 'WhatsApp Business para mejorar la comunicación con los clientes', 'tipo' => 'CURSO', 'area' => null],
            ['id' => 23, 'nombre' => 'Inglés profesional para actividades comerciales', 'tipo' => 'CURSO', 'area' => null],
        ];
        $matcher = new StudyMatcher($studies, ['comm0023' => 20, 'comt0411' => 21, 'ctrd0018' => 22, 'mf1002 2' => 23, 'ilustracion' => 5]);

        $found = static fn (int $id): array => ['ids' => [$id], 'pendientes' => []];
        self::assertSame($found(20), $matcher->match('ARTES GRÁFICAS (F250454AA) | COMM0023 – Instagram para tu Negocio'));
        self::assertSame($found(21), $matcher->match('2025/18/47/EDD1/0270 | COMT0411 GESTIÓN COMERCIAL DE VENTAS'));
        self::assertSame($found(23), $matcher->match('2025/18/47/EDD1/0270 MF1002_2 Inglés profesional para actividades comerciales'));
        self::assertSame($found(22), $matcher->match('F250454AA | CTRD0018 - WHATSHAPP BUSINESS PARA MEJORAR LA COMUNICACIÓN'), 'por el código, aunque el nombre traiga una errata');
        self::assertSame($found(20), $matcher->match('Curso: Instagram para tu negocio'), 'por el nombre, sin código');

        // Un alias de una sola palabra no se busca dentro de otros textos: sería adivinar.
        self::assertSame(['ciclo ilustracion y comic'], array_column($matcher->match('Ciclo ilustración y cómic')['pendientes'], 'clave'));
    }

    public function testLosCodigosSueltosNoSePreguntan(): void
    {
        $result = $this->matcher()->match('F250457AA | XX0001 Curso de cocina');

        self::assertSame([], $result['ids']);
        self::assertSame(['xx0001 curso de cocina'], array_column($result['pendientes'], 'clave'));
    }

    public function testTextoVacio(): void
    {
        self::assertSame(['ids' => [], 'pendientes' => []], $this->matcher()->match(null));
        self::assertSame(['ids' => [], 'pendientes' => []], $this->matcher()->match('  '));
    }
}
