<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

use Leads\Core\Request;
use Leads\Core\Response;
use Leads\Http\Routes;

/** Registro de actividad: todo lo que hace una comercial queda y administración lo ve por día. */
final class AuditTest extends DatabaseTestCase
{
    private function call(string $method, string $path, array $body = [], ?string $token = null, array $query = []): Response
    {
        $headers = $token ? ['authorization' => 'Bearer ' . $token] : [];

        return Routes::build($this->app)->dispatch(new Request($method, $path, $query, $body, $headers, [], '10.0.0.7'));
    }

    private function token(string $email): string
    {
        return $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => 'ClaveSegura123'])->data()['token'];
    }

    public function testUnDiaDeTrabajoDeUnaComercial(): void
    {
        $id = $this->lead(['nombre' => 'Eva', 'apellidos' => 'Pérez', 'email' => 'eva@example.com', 'telefono' => '600111222', 'estudioIds' => [2]]);
        $step = fn () => $this->clock->advance('+1 second');
        $step();
        $marta = $this->token('marta@test.es');

        foreach ([
            ['GET', "/api/leads/{$id}", ['vista' => '1']],
            ['GET', "/api/leads/{$id}", []],                 // recarga tras una acción: no cuenta
            ['POST', "/api/leads/{$id}/bloqueo", []],        // abre la llamada
            ['POST', "/api/leads/{$id}/bloqueo", []],        // doble clic: no se repite
            ['POST', "/api/leads/{$id}/marcar", []],
            ['POST', "/api/leads/{$id}/llamada", ['resultado' => 'NO_CONTESTA']],
            ['POST', "/api/leads/{$id}/whatsapp", []],
            ['POST', "/api/leads/{$id}/correo", []],
        ] as [$method, $path, $data]) {
            $step();
            $response = $method === 'GET' ? $this->call($method, $path, [], $marta, $data) : $this->call($method, $path, $data, $marta);
            self::assertLessThan(300, $response->status(), $path);
        }
        $this->clock->advance('+2 hours');
        $this->call('POST', "/api/leads/{$id}/llamada", ['resultado' => 'INTERESADO'], $marta);
        $step();
        $this->call('POST', "/api/leads/{$id}/notas", ['texto' => 'Viene el lunes'], $marta);

        $admin = $this->token('admin@test.es');
        self::assertSame(403, $this->call('GET', '/api/actividad', [], $marta)->status());
        $day = $this->call('GET', '/api/actividad', [], $admin, ['fecha' => '2026-09-15'])->data();

        $summary = array_column($day['resumen'], null, 'usuario')['Marta Comercial'];
        self::assertSame(2, $summary['llamadas']);
        self::assertSame(1, $summary['llamadasAbiertas']);
        self::assertSame(1, $summary['marcados']);
        self::assertSame(1, $summary['noContestan']);
        self::assertSame(1, $summary['contestan']);
        self::assertSame(1, $summary['interesados']);
        self::assertSame(1, $summary['whatsapp']);
        self::assertSame(1, $summary['correos']);
        self::assertSame(1, $summary['notas']);
        self::assertSame(1, $summary['leads']);
        self::assertSame('2026-09-15T08:00:00Z', $summary['primera'], 'empieza a las 10:00 de Madrid');
        self::assertSame('2026-09-15T10:00:10Z', $summary['ultima']);

        $marta = array_values(array_filter($day['eventos'], static fn ($e) => $e['usuario'] === 'Marta Comercial'));
        self::assertSame(
            ['NOTA', 'LLAMADA', 'CORREO', 'WHATSAPP', 'LLAMADA', 'MARCADO', 'LLAMADA_ABIERTA', 'FICHA', 'ENTRADA', 'IMPORTADO'],
            array_column($marta, 'tipo'),
            'lo más reciente primero, sin repetir dobles clics ni recargas',
        );
        self::assertSame('Eva Pérez', $marta[0]['lead']['nombre']);
        self::assertSame('Pulsa el teléfono', $marta[5]['accion']);
        self::assertSame('10.0.0.7', $this->pdo->query("SELECT ip FROM registro WHERE tipo = 'ENTRADA' LIMIT 1")->fetchColumn());

        // Filtro por usuario y otro día.
        $onlyAdmin = $this->call('GET', '/api/actividad', [], $admin, ['fecha' => '2026-09-15', 'usuario' => (string) $this->admin['id']])->data();
        self::assertSame(['ENTRADA'], array_column($onlyAdmin['eventos'], 'tipo'));
        self::assertSame([], $this->call('GET', '/api/actividad', [], $admin, ['fecha' => '2026-09-14'])->data()['eventos']);
        self::assertSame(422, $this->call('GET', '/api/actividad', [], $admin, ['fecha' => '15/09/2026'])->status());

        $csv = $this->call('GET', '/api/actividad/exportar', [], $admin, ['fecha' => '2026-09-15']);
        self::assertStringContainsString('actividad-2026-09-15.csv', $csv->headers()['Content-Disposition']);
        self::assertStringContainsString('15/09/2026;10:00:01;"Marta Comercial";"Entra en la aplicación"', $csv->body());
        self::assertStringContainsString('"Pulsa el teléfono";"Eva Pérez"', $csv->body());
    }

    public function testLosCambiosDeAjustesQuedanRegistrados(): void
    {
        $admin = $this->token('admin@test.es');
        $this->call('POST', '/api/usuarios', ['nombre' => 'Lucía', 'email' => 'lucia@test.es', 'password' => 'ClaveSegura123'], $admin);
        $this->call('POST', '/api/usuarios', ['nombre' => 'Mal', 'email' => 'mal', 'password' => 'x'], $admin); // falla: no se anota

        $settings = array_values(array_filter(
            $this->call('GET', '/api/actividad', [], $admin, ['fecha' => '2026-09-15'])->data()['eventos'],
            static fn ($e) => $e['tipo'] === 'AJUSTES',
        ));
        self::assertSame(['Crea el usuario lucia@test.es'], array_column($settings, 'resumen'));
    }
}
