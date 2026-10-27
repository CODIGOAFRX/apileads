<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

use Leads\Core\HttpException;

/** Estudios, alias y centros (Ajustes → Estudios y centros). */
final class CatalogServiceTest extends DatabaseTestCase
{
    private function study(array $overrides = []): array
    {
        return $this->app->catalog()->saveStudy(null, $overrides + [
            'nombre' => 'CFGS Fotografía', 'tipo' => 'CFGS', 'area' => 'AUDIOVISUAL', 'color' => '#a1b2c3', 'centroIds' => [1],
        ]);
    }

    public function testCreaYEditaUnEstudio(): void
    {
        $study = $this->study();
        self::assertSame('CFGS Fotografía', $study['nombre']);
        self::assertSame('#A1B2C3', $study['color'], 'el color se guarda en mayúsculas');
        self::assertSame([1], $study['centroIds']);
        self::assertTrue($study['activo']);

        $edited = $this->app->catalog()->saveStudy($study['id'], ['nombre' => ' CFGS Fotografía artística ', 'tipo' => 'CFGS', 'activo' => false, 'centroIds' => [1, 999]]);
        self::assertSame('CFGS Fotografía artística', $edited['nombre']);
        self::assertFalse($edited['activo']);
        self::assertSame([1], $edited['centroIds'], 'los centros que no existen se ignoran');
        self::assertNull($edited['area']);
    }

    public function testValidaLosDatosDelEstudio(): void
    {
        foreach ([
            'nombre' => ['nombre' => ''],
            'nombre largo' => ['nombre' => str_repeat('x', 161)],
            'Tipo de estudio' => ['tipo' => 'DOCTORADO'],
            'Área' => ['area' => 'COCINA'],
            'color' => ['color' => 'rojo'],
        ] as $what => $change) {
            try {
                $this->study($change);
                self::fail("Debía rechazar: {$what}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(HttpException::class);
        $this->app->catalog()->saveStudy(999, ['nombre' => 'X', 'tipo' => 'CFGS']);
    }

    public function testElOrdenNoDesbordaLaBaseDeDatos(): void
    {
        self::assertSame(9999, $this->study(['orden' => 99999999999])['orden']);
        self::assertSame(0, $this->study(['nombre' => 'Otro', 'orden' => -5])['orden']);
        self::assertGreaterThan(0, $this->study(['nombre' => 'Otro más', 'orden' => ['3']])['orden'], 'un orden no válido: al final');
    }

    public function testNoSeBorraUnEstudioConLeads(): void
    {
        $study = $this->study();
        $this->lead(['nombre' => 'Eva', 'email' => 'eva@example.com', 'estudioIds' => [$study['id']]]);
        try {
            $this->app->catalog()->deleteStudy($study['id']);
            self::fail('Debía impedirlo');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status());
            self::assertStringContainsString('Desactívalo', $e->getMessage());
        }

        $empty = $this->study(['nombre' => 'Sin leads']);
        $this->app->catalog()->deleteStudy($empty['id']);
        self::assertNull($this->app->catalogRepository()->study($empty['id']));
    }

    public function testLosAliasSeNormalizanYSeMuevenDeEstudio(): void
    {
        $catalog = $this->app->catalog();
        $study = $this->study();
        $withAlias = $catalog->addAlias($study['id'], '  Ciclo de FOTOGRAFÍA!! ', $this->clock->now());
        self::assertContains('ciclo de fotografia', array_column($withAlias['alias'], 'alias'));

        // El mismo alias en otro estudio: se mueve, no se duplica.
        $other = $this->study(['nombre' => 'Máster Fotografía', 'tipo' => 'MASTER']);
        $catalog->addAlias($other['id'], 'ciclo de fotografía', $this->clock->now());
        self::assertNotContains('ciclo de fotografia', array_column($this->app->catalogRepository()->study($study['id'])['alias'], 'alias'));
        self::assertSame($other['id'], $this->app->catalogRepository()->aliasMap()['ciclo de fotografia']);

        // Un alias enorme no rompe la columna (190).
        $long = $catalog->addAlias($study['id'], str_repeat('fotografía ', 100), $this->clock->now());
        self::assertLessThanOrEqual(190, max(array_map('strlen', array_column($long['alias'], 'alias'))));

        foreach (['   ', '¡¡!!'] as $empty) {
            try {
                $catalog->addAlias($study['id'], $empty, $this->clock->now());
                self::fail('Debía rechazar un alias vacío');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $aliasId = array_column($long['alias'], 'id')[0];
        $catalog->deleteAlias($aliasId);
        $catalog->deleteAlias(999999);
        self::assertNotContains($aliasId, array_column($this->app->catalogRepository()->study($study['id'])['alias'], 'id'));
    }

    public function testCentros(): void
    {
        $catalog = $this->app->catalog();
        $center = $catalog->saveCenter(null, ['nombre' => 'Estación Diseño Málaga', 'ciudad' => 'Málaga', 'provincia' => str_repeat('p', 500)]);
        self::assertSame('Málaga', $center['ciudad']);
        self::assertSame(120, mb_strlen($center['provincia']));

        $edited = $catalog->saveCenter($center['id'], ['nombre' => 'Málaga', 'activo' => false]);
        self::assertFalse($edited['activo']);
        self::assertNull($edited['ciudad']);

        try {
            $catalog->saveCenter(null, ['nombre' => ' ']);
            self::fail('Debía pedir el nombre');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(HttpException::class);
        $catalog->saveCenter(999, ['nombre' => 'X']);
    }

    public function testElCatalogoIncluyeLosOrigenes(): void
    {
        $catalog = $this->app->catalog()->catalog();
        self::assertCount(11, $catalog['origenes']);
        self::assertSame(10, count(array_filter(array_column($catalog['origenes'], 'seleccionable'))));
    }

    public function testUnaComercialCreaUnCursoCortoQueNoExiste(): void
    {
        $course = $this->app->catalog()->saveStudy(null, [
            'nombre' => '  Curso   intensivo de Procreate ', 'orden' => 1, 'activo' => false, 'centroIds' => [],
        ], $this->comercial);

        self::assertSame('Curso intensivo de Procreate', $course['nombre']);
        self::assertSame('CURSO', $course['tipo'], 'por defecto, curso');
        self::assertTrue($course['activo'], 'una comercial no crea estudios desactivados');
        self::assertGreaterThan(20, $course['orden'], 'va al final de la lista');
        self::assertSame('Marta Comercial', $course['creadoPor']);
        self::assertSame('2026-09-15T08:00:00Z', $course['creadoEn']);
        self::assertSame([], $course['centroIds'], 'si elige centros (ninguno) se respeta');

        // Y ya se puede asignar a un lead.
        $lead = $this->app->leads()->create(['nombre' => 'Eva', 'telefono' => '612345678', 'origen' => 'WEB', 'estudioIds' => [$course['id']]], $this->comercial)['lead'];
        self::assertSame(['Curso intensivo de Procreate'], array_column($lead['estudios'], 'nombre'));

        $sinCentros = $this->app->catalog()->saveStudy(null, ['nombre' => 'Curso de Blender'], $this->comercial);
        self::assertSame([1], $sinCentros['centroIds'], 'sin elegir: en todos los centros activos');
    }

    public function testNoSeDuplicaUnEstudioQueYaExiste(): void
    {
        $catalog = $this->app->catalog();
        foreach (['grado diseno grafico', 'GRADO DISEÑO GRÁFICO', 'Grado  Diseño  Gráfico.', 'grado en diseño gráfico'] as $name) {
            try {
                $catalog->saveStudy(null, ['nombre' => $name, 'tipo' => 'GRADO'], $this->comercial);
                self::fail("Debía detectar que ya existe: {$name}");
            } catch (HttpException $e) {
                self::assertSame(409, $e->status());
                self::assertSame(2, $e->details()['estudio']['id'], 'por el nombre o por su alias');
                self::assertStringContainsString('Ya existe «Grado Diseño Gráfico»', $e->getMessage());
            }
        }
        // Al editar, conservar su propio nombre no es un duplicado.
        self::assertSame('Grado Diseño Gráfico', $catalog->saveStudy(2, ['nombre' => 'Grado Diseño Gráfico', 'tipo' => 'GRADO'])['nombre']);
    }

    public function testLosEstudiosDeLaWebSonDeEstacionDisenoYElRestoDeGrupoEfp(): void
    {
        $company = array_column($this->app->catalogRepository()->studies(), 'empresa', 'nombre');
        foreach ([
            'Grado Diseño Moda', 'Grado Diseño Gráfico', 'CFGS Gráfica Audiovisual', 'CFGS Ilustración', 'CFGS Cómic',
            'CFGS Gráfica Interactiva', 'Máster Ilustración', 'Máster Profesional Ilustración Avanzada', 'Máster Profesional Moda',
            'Máster Profesional Gráfico', 'Máster Profesional Modelado y Animación 3D', 'Taller de mangas', 'Diseño artístico de videojuegos',
        ] as $name) {
            self::assertSame('ESTACION', $company[$name] ?? null, $name);
        }
        self::assertSame('EFP', $company['Instagram para tu negocio']);
        self::assertSame(['ESTACION' => 'Estación Diseño', 'EFP' => 'Grupo EFP'], $this->app->catalog()->catalog()['empresas']);
    }

    public function testSeEligeLaEmpresaDelEstudio(): void
    {
        $catalog = $this->app->catalog();
        self::assertSame('EFP', $this->study()['empresa'], 'si no se dice, de Grupo EFP');

        $course = $catalog->saveStudy(null, ['nombre' => 'Curso de Procreate', 'empresa' => 'ESTACION'], $this->comercial);
        self::assertSame('ESTACION', $course['empresa'], 'una comercial también puede decirlo al crearlo');
        self::assertSame('ESTACION', $catalog->saveStudy($course['id'], ['nombre' => 'Curso de Procreate avanzado', 'tipo' => 'CURSO'])['empresa'], 'al editar sin decirla se conserva');
        self::assertSame('EFP', $catalog->saveStudy($course['id'], ['nombre' => 'Curso de Procreate', 'tipo' => 'CURSO', 'empresa' => 'EFP'])['empresa']);

        $this->expectException(\InvalidArgumentException::class);
        $this->study(['nombre' => 'Otro', 'empresa' => 'ACME']);
    }

    public function testUnaComercialNoCambiaNiBorraEstudios(): void
    {
        $this->expectException(HttpException::class);
        $this->app->catalog()->saveStudy(2, ['nombre' => 'Otro nombre', 'tipo' => 'GRADO'], $this->comercial);
    }
}
