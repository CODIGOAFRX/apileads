<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

final class LeadSearchTest extends DatabaseTestCase
{
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ids = [
            'andres' => $this->lead(['nombre' => 'Andrés', 'apellidos' => 'García López', 'email' => 'andres.garcia@gmail.com', 'telefono' => '600 111 454', 'estudioIds' => [5], 'ubicacion' => 'Jaén']),
            'lucia' => $this->lead(['nombre' => 'Lucía', 'apellidos' => 'Andrade', 'email' => 'lucia@hotmail.com', 'telefono' => '611 222 333', 'estudioIds' => [2], 'origen' => 'FACEBOOK']),
            'pablo' => $this->lead(['nombre' => 'Pablo', 'email' => 'pablo@estudio.es', 'estudioIds' => [7, 1]]),
            'sin' => $this->lead(['nombre' => 'Sin Estudio', 'telefono' => '622 000 454']),
        ];
    }

    private function search(array $query): array
    {
        return $this->ids($this->app->leads()->search($query + ['bandeja' => 'todos'], $this->comercial));
    }

    public function testBuscaPorNombreQueContieneSinTildes(): void
    {
        self::assertEqualsCanonicalizing([$this->ids['andres'], $this->ids['lucia']], $this->search(['q' => 'andr']));
        self::assertSame([$this->ids['andres']], $this->search(['nombre' => 'ANDRES']));
        self::assertSame([$this->ids['andres']], $this->search(['q' => 'garcia andrés']), 'varias palabras en cualquier orden');
    }

    public function testBuscaTambienPorElNombreDeSusEstudios(): void
    {
        // andres: Máster Ilustración · pablo: Máster Profesional Gráfico y Grado Diseño Moda.
        self::assertSame([$this->ids['andres']], $this->search(['q' => 'ILUSTRACIÓN']), 'sin tildes ni mayúsculas');
        self::assertSame([$this->ids['pablo']], $this->search(['q' => 'moda']));
        self::assertSame([$this->ids['andres']], $this->search(['q' => 'garcia master']), 'una palabra en sus datos y otra en su estudio');
        self::assertEqualsCanonicalizing([$this->ids['andres'], $this->ids['pablo']], $this->search(['q' => 'master']));
        self::assertSame([], $this->search(['q' => 'lucia master']));

        $taller = $this->app->catalog()->saveStudy(null, ['nombre' => 'Taller de corsetería con Maya Hansen', 'tipo' => 'CURSO', 'empresa' => 'ESTACION']);
        $sac = $this->lead(['nombre' => 'Rosa', 'email' => 'rosa@example.com', 'estudioIds' => [$taller['id']]]);
        self::assertSame([$sac], $this->search(['q' => 'maya hansen']));
        self::assertSame([$sac], $this->search(['q' => 'corseteria']));
    }

    public function testBuscaTelefonosQueContienenLosDigitos(): void
    {
        self::assertEqualsCanonicalizing([$this->ids['andres'], $this->ids['sin']], $this->search(['q' => '454']));
        self::assertSame([$this->ids['lucia']], $this->search(['telefono' => '222 33']));
        self::assertSame([$this->ids['lucia']], $this->search(['q' => '+34 611']));
        self::assertSame([$this->ids['lucia']], $this->search(['telefono' => '0034611222333']));
    }

    public function testBuscaPorCorreoQueContiene(): void
    {
        self::assertSame([$this->ids['lucia']], $this->search(['email' => 'hotmail']));
        self::assertSame([$this->ids['pablo']], $this->search(['q' => '@estudio.es']));
    }

    public function testFiltraPorEstudioTipoYSinEstudio(): void
    {
        self::assertEqualsCanonicalizing([$this->ids['andres'], $this->ids['pablo']], $this->search(['tipo' => 'MASTER']));
        self::assertSame([$this->ids['pablo']], $this->search(['estudio' => '1,99']));
        self::assertSame([$this->ids['sin']], $this->search(['sinEstudio' => '1']));
        self::assertSame([$this->ids['lucia']], $this->search(['origen' => 'REDES_SOCIALES']));
        self::assertSame([$this->ids['andres']], $this->search(['ubicacion' => 'ja']));
    }

    public function testFiltraPorEmpresaSegunSusEstudios(): void
    {
        // 11: «Dirección por objetivos…», un curso de Grupo EFP.
        $efp = $this->lead(['nombre' => 'Curso EFP', 'email' => 'efp@example.com', 'estudioIds' => [11]]);
        $mixto = $this->lead(['nombre' => 'Mixto', 'email' => 'mixto@example.com', 'estudioIds' => [11, 2]]);

        self::assertEqualsCanonicalizing(
            [$this->ids['andres'], $this->ids['lucia'], $this->ids['pablo'], $mixto],
            $this->search(['empresa' => 'ESTACION']),
            'con un estudio de Estación Diseño ya es de Estación Diseño'
        );
        self::assertEqualsCanonicalizing([$this->ids['sin'], $efp], $this->search(['empresa' => 'EFP']), 'sin estudio también es de Grupo EFP');
        self::assertCount(6, $this->search(['empresa' => 'OTRA']), 'una empresa que no existe no filtra');

        $lead = $this->app->leads()->get($mixto, $this->comercial);
        self::assertSame(['ESTACION', 'Estación Diseño'], [$lead['empresa'], $lead['empresaLabel']]);
        self::assertSame('Grupo EFP', $this->app->leads()->get($this->ids['sin'], $this->comercial)['empresaLabel']);
    }

    public function testComodinesNoRompenLaBusqueda(): void
    {
        self::assertSame([], $this->search(['q' => '%']));
        self::assertSame([], $this->search(['q' => '_']));
    }

    public function testOrdenPorEntradaMasRecienteYPaginacion(): void
    {
        $this->clock->advance('+1 hour');
        $nuevo = $this->lead(['nombre' => 'Último', 'email' => 'ultimo@example.com']);

        $page = $this->app->leads()->search(['bandeja' => 'pendientes', 'porPagina' => 10], $this->comercial);

        self::assertSame($nuevo, $page['items'][0]['id']);
        self::assertSame(5, $page['total']);
        self::assertSame(5, $page['contadores']['pendientes']);
        self::assertSame('Andrés', $page['items'][4]['nombre']);
        self::assertSame(['id' => 5, 'nombre' => 'Máster Ilustración', 'tipo' => 'MASTER', 'color' => '#8CE5FF', 'empresa' => 'ESTACION'], $page['items'][4]['estudios'][0]);
    }

    public function testFiltraPorFechaDeEntradaEnHoraLocal(): void
    {
        self::assertCount(4, $this->search(['desde' => '2026-09-15', 'hasta' => '2026-09-15']));
        self::assertCount(0, $this->search(['desde' => '2026-09-16']));
    }
}
