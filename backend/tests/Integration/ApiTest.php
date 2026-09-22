<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

use Leads\Core\Request;
use Leads\Core\Response;
use Leads\Http\Routes;

final class ApiTest extends DatabaseTestCase
{
    private function call(string $method, string $path, array $body = [], ?string $token = null, array $query = []): Response
    {
        $headers = $token ? ['authorization' => 'Bearer ' . $token] : [];

        return Routes::build($this->app)->dispatch(new Request($method, $path, $query, $body, $headers, [], '127.0.0.1'));
    }

    private function token(string $email): string
    {
        return $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => 'ClaveSegura123'])->data()['token'];
    }

    public function testLoginYSesion(): void
    {
        $response = $this->call('POST', '/api/auth/login', ['email' => ' MARTA@test.es ', 'password' => 'ClaveSegura123']);
        self::assertSame(200, $response->status());
        self::assertSame('COMERCIAL', $response->data()['usuario']['rol']);

        $me = $this->call('GET', '/api/auth/me', [], $response->data()['token']);
        self::assertSame('Marta Comercial', $me->data()['nombre']);

        self::assertSame(401, $this->call('GET', '/api/leads')->status());
        self::assertSame(401, $this->call('GET', '/api/leads', [], 'token-falso')->status());
    }

    public function testLimitaIntentosDeLogin(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->call('POST', '/api/auth/login', ['email' => 'marta@test.es', 'password' => 'mal'])->status());
        }
        self::assertSame(429, $this->call('POST', '/api/auth/login', ['email' => 'marta@test.es', 'password' => 'ClaveSegura123'])->status());

        $this->clock->advance('+16 minutes');
        self::assertSame(200, $this->call('POST', '/api/auth/login', ['email' => 'marta@test.es', 'password' => 'ClaveSegura123'])->status());
    }

    public function testUnUsuarioDesactivadoPierdeElAcceso(): void
    {
        $token = $this->token('marta@test.es');
        $admin = $this->token('admin@test.es');

        $this->call('PUT', '/api/usuarios/' . $this->comercial['id'], ['activo' => false], $admin);

        self::assertSame(401, $this->call('GET', '/api/leads', [], $token)->status());
        self::assertSame(401, $this->call('POST', '/api/auth/login', ['email' => 'marta@test.es', 'password' => 'ClaveSegura123'])->status());
    }

    public function testLasRutasDeAdministracionEstanProtegidas(): void
    {
        $comercial = $this->token('marta@test.es');
        $admin = $this->token('admin@test.es');

        self::assertSame(403, $this->call('GET', '/api/usuarios', [], $comercial)->status());
        self::assertSame(403, $this->call('POST', '/api/estudios', ['nombre' => 'X'], $comercial)->status());
        self::assertSame(200, $this->call('GET', '/api/usuarios', [], $admin)->status());
        self::assertSame(200, $this->call('GET', '/api/catalogo', [], $comercial)->status());

        $created = $this->call('POST', '/api/usuarios', ['nombre' => 'Nueva', 'email' => 'nueva@test.es', 'password' => 'corta'], $admin);
        self::assertSame(422, $created->status());
        self::assertStringContainsString('10 caracteres', $created->data()['error']);

        $lastAdmin = $this->call('PUT', '/api/usuarios/' . $this->admin['id'], ['rol' => 'COMERCIAL'], $admin);
        self::assertSame(409, $lastAdmin->status());
    }

    public function testFlujoDeLlamadaPorHttp(): void
    {
        $token = $this->token('marta@test.es');

        $created = $this->call('POST', '/api/leads', ['nombre' => 'Eva', 'telefono' => '612345678', 'estudioIds' => [5]], $token);
        self::assertSame(201, $created->status());
        $id = $created->data()['lead']['id'];

        $duplicate = $this->call('POST', '/api/leads', ['nombre' => 'Eva 2', 'telefono' => '+34612345678'], $token);
        self::assertSame(409, $duplicate->status());
        self::assertSame($id, $duplicate->data()['detalles']['duplicado']['id']);

        self::assertSame(200, $this->call('POST', "/api/leads/{$id}/bloqueo", [], $token)->status());
        $call = $this->call('POST', "/api/leads/{$id}/llamada", ['resultado' => 'NO_CONTESTA'], $token);
        self::assertSame('no-contestan', $call->data()['bandeja']);

        $invalid = $this->call('POST', "/api/leads/{$id}/llamada", ['resultado' => 'QUIZAS'], $token);
        self::assertSame(422, $invalid->status());

        $list = $this->call('GET', '/api/leads', [], $token, ['bandeja' => 'no-contestan', 'q' => '345']);
        self::assertSame([$id], array_column($list->data()['items'], 'id'));

        $scheduled = $this->call('POST', "/api/leads/{$id}/llamada-agendada", ['fecha' => '2026-09-17T12:15', 'nota' => 'Mejor el jueves'], $token);
        self::assertSame(200, $scheduled->status());
        self::assertSame('agendadas', $scheduled->data()['bandeja']);
        self::assertSame(422, $this->call('POST', "/api/leads/{$id}/llamada-agendada", ['fecha' => 'mañana'], $token)->status());

        $interested = $this->call('POST', "/api/leads/{$id}/interesado", [], $token);
        self::assertSame('interesados', $interested->data()['bandeja']);
        self::assertSame(422, $this->call('POST', "/api/leads/{$id}/interesado", [], $token)->status());

        self::assertSame(404, $this->call('GET', '/api/leads/9999', [], $token)->status());
        self::assertSame(405, $this->call('DELETE', '/api/leads', [], $token)->status());
        self::assertSame(404, $this->call('GET', '/api/nada', [], $token)->status());
    }

    public function testDescargasDePlantillaYExportacion(): void
    {
        $token = $this->token('marta@test.es');
        $this->lead(['nombre' => '=HYPERLINK("x")', 'email' => 'a@example.com']);

        $xlsx = $this->call('GET', '/api/importaciones/plantilla', [], $token);
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx->headers()['Content-Type']);

        $csv = $this->call('GET', '/api/leads/exportar', [], $token, ['bandeja' => 'todos']);
        self::assertStringContainsString('attachment; filename="leads-todos-', $csv->headers()['Content-Disposition']);
        self::assertStringContainsString("'=HYPERLINK", $csv->body(), 'evita inyección de fórmulas');
        self::assertStringStartsWith("\xEF\xBB\xBFID;Nombre;", $csv->body());
    }
}
