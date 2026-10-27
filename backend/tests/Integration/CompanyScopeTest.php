<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

use Leads\Core\HttpException;
use Leads\Infrastructure\Spreadsheet\SpreadsheetReader;

/** Usuarios limitados a Estación Diseño o a Grupo EFP (Ajustes → Usuarios). */
final class CompanyScopeTest extends DatabaseTestCase
{
    private array $granada;
    private array $almeria;
    private int $moda;
    private int $recepcion;

    protected function setUp(): void
    {
        parent::setUp();
        $auth = $this->app->auth();
        $this->granada = $auth->createUser(['nombre' => 'Vero Granada', 'email' => 'vero@test.es', 'password' => 'ClaveSegura123', 'rol' => 'COMERCIAL', 'empresa' => 'ESTACION']);
        $this->almeria = $auth->createUser(['nombre' => 'Luis Almería', 'email' => 'luis@test.es', 'password' => 'ClaveSegura123', 'rol' => 'COMERCIAL', 'empresa' => 'EFP']);
        // 1: Grado Diseño Moda (Estación Diseño) · 19: Gestión de recepción (Grupo EFP).
        $this->moda = $this->lead(['nombre' => 'Ana Moda', 'email' => 'ana@example.com', 'estudioIds' => [1]]);
        $this->recepcion = $this->lead(['nombre' => 'Pepe Recepción', 'email' => 'pepe@example.com', 'estudioIds' => [19]]);
        $this->lead(['nombre' => 'Sin Estudio', 'telefono' => '600111222']);
    }

    private function names(array $viewer, array $query = []): array
    {
        return array_column($this->app->leads()->search($query + ['bandeja' => 'todos'], $viewer)['items'], 'nombre');
    }

    public function testCadaUnoVeSoloLosLeadsDeSuEmpresa(): void
    {
        self::assertSame(['Ana Moda'], $this->names($this->granada));
        self::assertEqualsCanonicalizing(['Pepe Recepción', 'Sin Estudio'], $this->names($this->almeria));
        self::assertCount(3, $this->names($this->comercial), 'sin empresa, las dos');
        self::assertSame(['Ana Moda'], $this->names($this->granada, ['empresa' => 'EFP']), 'el filtro de empresa no se salta el límite');

        self::assertSame(1, $this->app->leads()->counters($this->granada)['pendientes']);
        self::assertSame(2, $this->app->leads()->counters($this->almeria)['pendientes']);
        self::assertSame(3, $this->app->leads()->counters($this->comercial)['pendientes']);
    }

    public function testNoPuedeAbrirNiTrabajarUnLeadDeLaOtraEmpresa(): void
    {
        $leads = $this->app->leads();
        self::assertSame('Ana Moda', $leads->get($this->moda, $this->granada)['nombre']);
        foreach ([
            'ficha' => fn () => $leads->get($this->recepcion, $this->granada),
            'llamada' => fn () => $leads->registerCall($this->recepcion, 'INTERESADO', null, $this->granada),
            'editar' => fn () => $leads->update($this->moda, ['nombre' => 'X', 'estudioIds' => [1]], $this->almeria),
            'correo' => fn () => $this->app->mail()->preview($this->moda, $this->almeria),
        ] as $what => $action) {
            try {
                $action();
                self::fail("Debía impedirlo: {$what}");
            } catch (HttpException $e) {
                self::assertSame(404, $e->status(), $what);
            }
        }
    }

    public function testSoloDaDeAltaLeadsDeSuEmpresa(): void
    {
        $leads = $this->app->leads();
        $base = ['nombre' => 'Nueva', 'telefono' => '611000111', 'origen' => 'WEB'];

        foreach ([[$this->granada, []], [$this->granada, [19]], [$this->almeria, [1]], [$this->almeria, [1, 19]]] as [$viewer, $studies]) {
            try {
                $leads->create($base + ['estudioIds' => $studies], $viewer);
                self::fail('Debía rechazarlo');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('con tu usuario solo se dan de alta leads de', $e->getMessage());
            }
        }

        self::assertSame('ESTACION', $leads->create($base + ['estudioIds' => [1, 19]], $this->granada)['lead']['empresa'], 'con un estudio suyo, sí');
        self::assertSame('EFP', $leads->create(['nombre' => 'Otra', 'telefono' => '622000222', 'origen' => 'WEB'], $this->almeria)['lead']['empresa']);
    }

    public function testUnDuplicadoDeLaOtraEmpresaSeAvisaSinEnsenarlo(): void
    {
        try {
            $this->app->leads()->create(['nombre' => 'Pepe', 'email' => 'pepe@example.com', 'origen' => 'WEB', 'estudioIds' => [1], 'fusionar' => true], $this->granada);
            self::fail('Debía avisar');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status());
            self::assertSame('Ese correo o teléfono ya es de un lead de Grupo EFP: lo lleva su equipo', $e->getMessage());
            self::assertSame([], $e->details(), 'sin los datos del lead');
        }
        self::assertSame(['Gestión de recepción'], array_column($this->app->leads()->get($this->recepcion, $this->almeria)['estudios'], 'nombre'), 'no se ha tocado');
    }

    public function testSoloVeYCreaEstudiosDeSuEmpresa(): void
    {
        $catalog = $this->app->catalog();
        $empresas = static fn (array $viewer): array => array_values(array_unique(array_column($catalog->catalog($viewer)['estudios'], 'empresa')));
        self::assertSame(['ESTACION'], $empresas($this->granada));
        self::assertSame(['EFP'], $empresas($this->almeria));
        self::assertEqualsCanonicalizing(['ESTACION', 'EFP'], $empresas($this->comercial));

        self::assertSame('EFP', $catalog->saveStudy(null, ['nombre' => 'Curso de Excel', 'empresa' => 'ESTACION'], $this->almeria)['empresa'], 'no puede elegir la otra');
    }

    public function testAlImportarSeOmitenLasFilasDeLaOtraEmpresa(): void
    {
        $table = (new SpreadsheetReader())->readCsvString(
            "Nombre;Email;Estudio\n"
            . "Marta;marta@example.com;Grado Diseño Gráfico\n"
            . "Jorge;jorge@example.com;Gestión de recepción\n"
            . "Pepe;pepe@example.com;Grado Diseño Moda\n"
        );
        $report = $this->app->imports()->import($table, 'feria.csv', [], (int) $this->granada['id'], $this->granada);

        self::assertSame([1, 2], [$report['creados'], $report['omitidos']]);
        self::assertStringContainsString('solo se dan de alta leads de Estación Diseño', $report['incidencias'][0]['mensaje']);
        self::assertStringContainsString('ya es de un lead de Grupo EFP', $report['incidencias'][1]['mensaje']);
        self::assertEqualsCanonicalizing(['Ana Moda', 'Marta'], $this->names($this->granada));
    }

    public function testAdministracionDecideLaEmpresaDeCadaUsuario(): void
    {
        $auth = $this->app->auth();
        self::assertSame('ESTACION', $this->granada['empresa']);
        self::assertNull($this->comercial['empresa'] ?? null, 'por defecto, las dos');

        self::assertSame('EFP', $auth->updateUser($this->granada['id'], ['empresa' => 'EFP'], (int) $this->admin['id'])['empresa']);
        self::assertSame('EFP', $auth->updateUser($this->granada['id'], ['nombre' => 'Vero'], (int) $this->admin['id'])['empresa'], 'sin indicarla se conserva');
        self::assertNull($auth->updateUser($this->granada['id'], ['empresa' => ''], (int) $this->admin['id'])['empresa'], 'vacío: las dos');

        $this->expectException(\InvalidArgumentException::class);
        $auth->updateUser($this->granada['id'], ['empresa' => 'ACME'], (int) $this->admin['id']);
    }
}
