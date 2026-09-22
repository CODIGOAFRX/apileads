<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

use Leads\Core\HttpException;

final class LeadServiceTest extends DatabaseTestCase
{
    public function testCicloCompletoDeNoContesta(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678', 'email' => 'eva@example.com']);

        $lead = $service->registerCall($id, 'NO_CONTESTA', 'Buzón de voz', $this->comercial);
        self::assertSame('no-contestan', $lead['bandeja']);
        self::assertSame('2026-09-16T07:00:00Z', $lead['proximaLlamada'], 'miércoles 9:00 en Madrid');
        self::assertSame(['llamar', 'whatsapp', 'enviar-info', 'interesado', 'descartar'], $lead['acciones'], 'WhatsApp y correo desde el primer intento');
        self::assertSame('Buzón de voz', $lead['historial'][0]['detalle']['nota']);
        self::assertSame('Marta Comercial', $lead['historial'][0]['usuario']);
        self::assertSame(0, $service->counters()['pendientes']);

        // Miércoles a las 9:00 vuelve a pendientes.
        $this->clock->set('2026-09-16T09:00:00+02:00');
        self::assertSame(1, $service->counters()['pendientes']);
        $service->registerCall($id, 'NO_CONTESTA', null, $this->comercial);

        $this->clock->set('2026-09-17T09:30:00+02:00');
        $lead = $service->registerCall($id, 'NO_CONTESTA', null, $this->comercial);
        self::assertSame(3, $lead['intentos']);
        self::assertStringStartsWith('https://wa.me/34612345678?text=Hola%20Eva%2C%20soy%20Marta', $lead['whatsappUrl']);
        self::assertSame('2026-09-18T07:00:00Z', $lead['proximaLlamada']);

        $service->logWhatsapp($id, $this->comercial);
        self::assertSame('WHATSAPP', $service->get($id, $this->comercial)['historial'][0]['tipo']);
    }

    public function testInteresadoYNoInteresadoCambianDePestana(): void
    {
        $service = $this->app->leads();
        $a = $this->lead(['nombre' => 'A', 'telefono' => '600000001', 'email' => 'a@example.com']);
        $b = $this->lead(['nombre' => 'B', 'telefono' => '600000002']);

        $leadA = $service->registerCall($a, 'INTERESADO', null, $this->comercial);
        $leadB = $service->registerCall($b, 'NO_INTERESADO', null, $this->comercial);

        self::assertSame('interesados', $leadA['bandeja']);
        self::assertContains('enviar-info', $leadA['acciones']);
        self::assertSame('descartados', $leadB['bandeja']);
        self::assertSame(['reabrir'], $leadB['acciones']);
        self::assertSame(['pendientes' => 0, 'agendadas' => 0, 'no-contestan' => 0, 'interesados' => 1, 'matriculados' => 0, 'descartados' => 1, 'todos' => 2], $service->counters());

        $leadA = $service->scheduleAppointment($a, '2026-09-18T11:30', 'Viene con su madre', $this->comercial);
        self::assertSame('CITA', $leadA['estado']);
        self::assertSame('2026-09-18T09:30:00Z', $leadA['citaEn']);
        self::assertSame('matriculados', $service->enroll($a, null, $this->comercial)['bandeja']);
        self::assertSame('pendientes', $service->reopen($b, $this->comercial)['bandeja']);
    }

    public function testNoSePuedeRegistrarLlamadaSinTelefono(): void
    {
        $id = $this->lead(['nombre' => 'Sin teléfono', 'email' => 'x@example.com']);

        $lead = $this->app->leads()->get($id, $this->comercial);
        self::assertSame(['enviar-info', 'interesado', 'descartar'], $lead['acciones']);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->leads()->registerCall($id, 'NO_CONTESTA', null, $this->comercial);
    }

    public function testElBloqueoEvitaQueDosComercialesLlamenALaVez(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678']);

        $lead = $service->lock($id, $this->comercial);
        self::assertTrue($lead['bloqueo']['esMio']);
        // La misma persona puede volver a reservarlo en el mismo segundo (en MariaDB
        // el UPDATE no cambia nada y antes decía que ella misma estaba llamando).
        self::assertTrue($service->lock($id, $this->comercial)['bloqueo']['esMio']);

        $viewByAdmin = $service->get($id, $this->admin);
        self::assertSame('Marta Comercial', $viewByAdmin['bloqueo']['usuario']);
        self::assertFalse($viewByAdmin['bloqueo']['esMio']);

        try {
            $service->lock($id, $this->admin);
            self::fail('Debía estar bloqueado');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status());
            self::assertStringContainsString('Marta Comercial está llamando', $e->getMessage());
        }

        // Al registrar la llamada se libera.
        $service->registerCall($id, 'INTERESADO', null, $this->comercial);
        self::assertNull($service->get($id, $this->admin)['bloqueo']);

        // Un bloqueo caducado no impide llamar.
        $service->lock($id, $this->comercial);
        $this->clock->advance('+6 minutes');
        self::assertTrue($service->lock($id, $this->admin)['bloqueo']['esMio']);
    }

    public function testLlamadaAgendadaEsperaYASuHoraSaleLaPrimeraDePendientes(): void
    {
        $service = $this->app->leads();
        $old = $this->lead(['nombre' => 'Antiguo', 'telefono' => '600000001']);
        $this->clock->advance('+1 minute');
        $scheduled = $this->lead(['nombre' => 'Agendado', 'telefono' => '600000002']);
        $this->clock->advance('+1 minute');
        $new = $this->lead(['nombre' => 'Nuevo', 'telefono' => '600000003']);

        $service->lock($scheduled, $this->comercial);
        $lead = $service->scheduleCall($scheduled, '2026-09-16T17:00', 'Está en clase', $this->comercial);
        self::assertSame('AGENDADO', $lead['estado']);
        self::assertSame('agendadas', $lead['bandeja']);
        self::assertSame('2026-09-16T15:00:00Z', $lead['llamadaAgendada']);
        self::assertFalse($lead['destacado']);
        self::assertNull($lead['bloqueo'], 'se libera la reserva');
        self::assertSame('Llamada agendada para el 16/09/2026 a las 17:00', $lead['historial'][0]['resumen']);
        self::assertSame('Está en clase', $lead['historial'][0]['detalle']['nota']);
        self::assertSame([$new, $old], $this->ids($service->search([], $this->comercial)));
        self::assertSame(1, $service->counters()['agendadas']);

        // A su hora vuelve a pendientes, la primera aunque sea más antigua, y destacada.
        $this->clock->set('2026-09-16T17:00:00+02:00');
        $page = $service->search([], $this->comercial);
        self::assertSame([$scheduled, $new, $old], $this->ids($page));
        self::assertTrue($page['items'][0]['destacado']);
        self::assertSame(0, $page['contadores']['agendadas']);

        // Al llamarle se cumple: deja de destacar y sigue el flujo normal.
        $lead = $service->registerCall($scheduled, 'NO_CONTESTA', null, $this->comercial);
        self::assertSame('no-contestan', $lead['bandeja']);
        self::assertNull($lead['llamadaAgendada']);
        self::assertFalse($lead['destacado']);
    }

    public function testNoSeAgendaEnElPasadoNiSiOtraPersonaEstaLlamando(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678']);

        try {
            $service->scheduleCall($id, '2026-09-15T09:00', null, $this->comercial);
            self::fail('Debía rechazar una hora pasada');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('aún no hayan pasado', $e->getMessage());
        }

        $service->lock($id, $this->admin);
        $this->expectException(HttpException::class);
        $service->scheduleCall($id, '2026-09-16T10:00', null, $this->comercial);
    }

    public function testBotonDeInteresadoSinLlamar(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678', 'email' => 'eva@example.com']);
        $service->registerCall($id, 'NO_CONTESTA', null, $this->comercial);

        $lead = $service->markInterested($id, $this->comercial);

        self::assertSame('INTERESADO', $lead['estado']);
        self::assertSame('interesados', $lead['bandeja']);
        self::assertSame(0, $lead['intentos']);
        self::assertNotContains('interesado', $lead['acciones']);
        self::assertSame('Marcado como interesado', $lead['historial'][0]['resumen']);

        $this->expectException(\InvalidArgumentException::class);
        $service->markInterested($id, $this->comercial);
    }

    public function testAltaManualAvisaDeDuplicadosYPermiteFusionar(): void
    {
        $service = $this->app->leads();
        $created = $service->create(['nombre' => 'LUCÍA', 'telefono' => '600 12 34 54', 'estudioIds' => [2, 999]], $this->comercial);
        self::assertSame('Lucía', $created['lead']['nombre']);
        self::assertSame('MANUAL', $created['lead']['origen']);
        self::assertSame([2], array_column($created['lead']['estudios'], 'id'));

        try {
            $service->create(['nombre' => 'Lucía M.', 'telefono' => '+34600123454', 'email' => 'lucia@example.com'], $this->comercial);
            self::fail('Debía detectar el duplicado');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status());
            self::assertSame($created['lead']['id'], $e->details()['duplicado']['id']);
        }

        $merged = $service->create(['nombre' => 'Lucía M.', 'telefono' => '+34600123454', 'email' => 'lucia@example.com', 'estudioIds' => [5], 'fusionar' => true], $this->comercial);
        self::assertSame('fusionado', $merged['accion']);
        self::assertSame($created['lead']['id'], $merged['lead']['id']);
        self::assertSame('Lucía', $merged['lead']['nombre'], 'no se pisa lo que ya había');
        self::assertSame('lucia@example.com', $merged['lead']['email'], 'se completa lo que faltaba');
        self::assertSame([2, 5], array_column($merged['lead']['estudios'], 'id'));
    }

    public function testAltaManualSinDatosDeContacto(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app->leads()->create(['ubicacion' => 'Granada'], $this->comercial);
    }

    public function testEdicionRegistraLosCamposCambiados(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678', 'estudioIds' => [1]]);
        $other = $this->lead(['nombre' => 'Otro', 'email' => 'otro@example.com']);

        $lead = $service->update($id, ['nombre' => 'Eva', 'apellidos' => 'Ruiz', 'telefono' => '612345678', 'estudioIds' => [1, 2], 'origen' => 'WEB'], $this->comercial);
        self::assertSame('Datos editados: apellidos, estudios', $lead['historial'][0]['resumen']);

        $this->expectException(HttpException::class);
        $service->update($id, ['nombre' => 'Eva', 'email' => 'otro@example.com'], $this->comercial);
    }

    public function testUnDescartadoQueVuelveAEntrarSeReactiva(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678', 'email' => 'eva@example.com']);
        $service->registerCall($id, 'NO_INTERESADO', null, $this->comercial);

        $this->clock->advance('+30 days');
        $again = $this->lead(['nombre' => 'Eva R.', 'email' => 'EVA@example.com', 'interes' => 'Máster moda', 'origen' => 'FACEBOOK']);

        $lead = $service->get($again, $this->comercial);
        self::assertSame($id, $again);
        self::assertSame('pendientes', $lead['bandeja']);
        self::assertSame('2026-10-15T08:00:00Z', $lead['entrada'], 'sube arriba del listado');
        self::assertSame('WEB', $lead['origen'], 'se conserva el origen original');
        self::assertSame(['CAMBIO_ESTADO', 'FUSIONADO'], array_slice(array_column($lead['historial'], 'tipo'), 0, 2));
    }
}
