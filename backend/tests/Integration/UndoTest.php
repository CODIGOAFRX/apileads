<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

use Leads\Core\HttpException;
use Leads\Core\Request;
use Leads\Core\Response;
use Leads\Http\Routes;

final class UndoTest extends DatabaseTestCase
{
    public function testCancelarLaLlamadaAgendadaDejaDeAvisarYVuelveAPendientes(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678']);
        $service->scheduleCall($id, '2026-09-18T08:00', 'Mejor el viernes', $this->comercial);
        $this->clock->set('2026-09-17T09:00:00+02:00');
        self::assertCount(1, $service->reminders($this->comercial)['llamadas'], 'avisaría a las 8:00 del viernes');

        $lead = $service->cancelScheduledCall($id, $this->comercial);
        self::assertSame('NUEVO', $lead['estado']);
        self::assertSame('pendientes', $lead['bandeja']);
        self::assertNull($lead['llamadaAgendada']);
        self::assertNotContains('cancelar-agendada', $lead['acciones']);
        self::assertSame('Llamada agendada cancelada (era el 18/09/2026 a las 08:00)', $lead['historial'][0]['resumen']);
        self::assertSame([], $service->reminders($this->comercial)['llamadas']);
        self::assertSame(0, $service->counters()['agendadas']);
    }

    public function testUnInteresadoConLlamadaAgendadaSigueEnInteresados(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678']);
        $service->markInterested($id, $this->comercial);
        $lead = $service->scheduleCall($id, '2026-09-16T17:00', null, $this->comercial);
        self::assertContains('cancelar-agendada', $lead['acciones']);

        $lead = $service->cancelScheduledCall($id, $this->comercial);
        self::assertSame('INTERESADO', $lead['estado']);
        self::assertSame('interesados', $lead['bandeja']);

        $this->expectException(\InvalidArgumentException::class);
        $service->cancelScheduledCall($id, $this->comercial);
    }

    public function testDeshacerYRehacerUnaCancelacion(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678']);
        $service->scheduleCall($id, '2026-09-16T08:00', null, $this->comercial);
        $service->cancelScheduledCall($id, $this->comercial);

        $state = $service->undoState($this->comercial);
        self::assertSame('Llamada agendada cancelada (era el 16/09/2026 a las 08:00)', $state['deshacer']['descripcion']);
        self::assertSame('Eva', $state['deshacer']['nombre']);
        self::assertNull($state['rehacer']);
        self::assertSame([], $service->reminders($this->comercial)['llamadas']);

        $result = $service->undoLast($this->comercial);
        self::assertSame('agendadas', $result['lead']['bandeja']);
        self::assertSame('2026-09-16T06:00:00Z', $result['lead']['llamadaAgendada']);
        self::assertSame('Deshecho: Llamada agendada cancelada (era el 16/09/2026 a las 08:00)', $result['lead']['historial'][0]['resumen']);
        self::assertStringStartsWith('Llamada agendada para', $result['deshacer']['descripcion'], 'lo siguiente que se deshace es el agendado');
        self::assertNotNull($result['rehacer']);

        // La llamada vuelve a avisar a quien la agendó.
        self::assertSame([$id], array_column($service->reminders($this->comercial)['llamadas'], 'id'));
        self::assertTrue($service->reminders($this->comercial)['llamadas'][0]['mia']);

        $result = $service->redoLast($this->comercial);
        self::assertSame('pendientes', $result['lead']['bandeja']);
        self::assertNull($result['lead']['llamadaAgendada']);
        self::assertNull($result['rehacer']);
    }

    public function testSeDeshaceEnOrdenYUnCambioNuevoBorraLoQueQuedabaPorRehacer(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678']);
        $service->registerCall($id, 'NO_CONTESTA', null, $this->comercial);
        $service->discard($id, 'Ya estudia en otro sitio', $this->comercial);

        self::assertSame('NO_CONTESTA', $service->undoLast($this->comercial)['lead']['estado']);
        $lead = $service->undoLast($this->comercial)['lead'];
        self::assertSame('NUEVO', $lead['estado']);
        self::assertSame(0, $lead['intentos']);
        self::assertNull($lead['ultimaLlamada']);

        try {
            $service->undoLast($this->comercial);
            self::fail('No quedaba nada que deshacer');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('No hay nada que deshacer', $e->getMessage());
        }

        // Rehacer la primera y hacer algo nuevo: la segunda ya no se puede rehacer.
        self::assertSame('NO_CONTESTA', $service->redoLast($this->comercial)['lead']['estado']);
        $service->markInterested($id, $this->comercial);
        self::assertNull($service->undoState($this->comercial)['rehacer']);
        self::assertSame('Marcado como interesado', $service->undoState($this->comercial)['deshacer']['descripcion']);
    }

    public function testDeshacerUnaEdicionDevuelveLosDatosYLosEstudios(): void
    {
        $service = $this->app->leads();
        $studies = array_column($this->app->catalogRepository()->studies(), 'id');
        $lead = $service->create(['nombre' => 'Eva', 'telefono' => '612345678', 'origen' => 'WEB', 'estudioIds' => [$studies[0]]], $this->admin)['lead'];
        $service->update($lead['id'], [
            'nombre' => 'Eva María', 'apellidos' => 'López', 'telefono' => '612345678', 'origen' => 'FERIA', 'estudioIds' => [$studies[1]],
        ], $this->admin);

        $undone = $service->undoLast($this->admin)['lead'];
        self::assertSame('Eva', $undone['nombre']);
        self::assertSame('', $undone['apellidos']);
        self::assertSame('WEB', $undone['origen']);
        self::assertSame([$studies[0]], array_column($undone['estudios'], 'id'));
        self::assertSame([$lead['id']], $this->ids($service->search(['q' => 'eva'], $this->admin)), 'el buscador vuelve a encontrarlo por su nombre');
        self::assertSame([], $this->ids($service->search(['q' => 'lopez'], $this->admin)));
    }

    public function testCadaUsuarioDeshaceLoSuyoYNoPisaElTrabajoDeOtro(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678']);
        $service->registerCall($id, 'NO_CONTESTA', null, $this->comercial);
        self::assertNull($service->undoState($this->admin)['deshacer'], 'la administradora no ve lo de la comercial');

        // Otra persona trabaja el lead después: ya no se puede deshacer sin pisarla.
        $service->markInterested($id, $this->admin);
        try {
            $service->undoLast($this->comercial);
            self::fail('Debía negarse a deshacer');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status());
            self::assertStringContainsString('Eva ha cambiado después de «No contesta (intento 1)»', $e->getMessage());
        }
        self::assertSame('INTERESADO', $service->get($id, $this->comercial)['estado']);
        self::assertNull($service->undoState($this->comercial)['deshacer'], 'el cambio que ya no se puede deshacer se olvida');
    }

    public function testSoloSeDeshaceLoDeLasUltimas12Horas(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678']);
        $service->markInterested($id, $this->comercial);
        $this->clock->advance('+13 hours');
        self::assertNull($service->undoState($this->comercial)['deshacer']);
    }

    public function testLasNotasYLosCorreosNoSeDeshacen(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678']);
        $service->addNote($id, 'Prefiere WhatsApp', $this->comercial);
        $service->logWhatsapp($id, $this->comercial);
        self::assertNull($service->undoState($this->comercial)['deshacer']);
    }

    public function testRutasDeDeshacerYCancelar(): void
    {
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678']);
        $this->app->leads()->scheduleCall($id, '2026-09-18T08:00', null, $this->comercial);
        $token = $this->call('POST', '/api/auth/login', '', ['email' => 'marta@test.es', 'password' => 'ClaveSegura123'])->data()['token'];

        $response = $this->call('DELETE', "/api/leads/{$id}/llamada-agendada", $token);
        self::assertSame(200, $response->status());
        self::assertSame(200, $this->call('GET', '/api/deshacer', $token)->status());
        self::assertSame(200, $this->call('POST', '/api/deshacer', $token)->status());
        self::assertSame(200, $this->call('POST', '/api/rehacer', $token)->status());
        self::assertSame(422, $this->call('POST', '/api/rehacer', $token)->status(), 'no queda nada que rehacer');
    }

    private function call(string $method, string $path, string $token, array $body = []): Response
    {
        $headers = $token !== '' ? ['authorization' => 'Bearer ' . $token] : [];

        return Routes::build($this->app)->dispatch(new Request($method, $path, [], $body, $headers, [], '127.0.0.1'));
    }
}
