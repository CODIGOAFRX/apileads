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
        self::assertStringStartsWith('https://wa.me/34612345678?text=', $lead['whatsappUrl']);
        self::assertStringStartsWith("Buenos días, Eva:\nSoy Marta, de GRUPO EFP.", rawurldecode(explode('text=', $lead['whatsappUrl'])[1]));
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
        self::assertSame(['pendientes' => 0, 'agendadas' => 0, 'no-contestan' => 0, 'interesados' => 1, 'matriculados' => 0, 'descartados' => 1, 'historico' => 1, 'todos' => 2], $service->counters());

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
        $created = $service->create(['nombre' => 'LUCÍA', 'telefono' => '600 12 34 54', 'estudioIds' => [2, 999], 'origen' => 'COLEGIOS'], $this->comercial);
        self::assertSame('Lucía', $created['lead']['nombre']);
        self::assertSame('COLEGIOS', $created['lead']['origen']);
        self::assertSame('Colegios', $created['lead']['origenLabel']);
        self::assertSame([2], array_column($created['lead']['estudios'], 'id'));

        try {
            $service->create(['nombre' => 'Lucía M.', 'telefono' => '+34600123454', 'email' => 'lucia@example.com', 'origen' => 'WEB'], $this->comercial);
            self::fail('Debía detectar el duplicado');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status());
            self::assertSame($created['lead']['id'], $e->details()['duplicado']['id']);
        }

        $merged = $service->create(['nombre' => 'Lucía M.', 'telefono' => '+34600123454', 'email' => 'lucia@example.com', 'estudioIds' => [5], 'origen' => 'WEB', 'fusionar' => true], $this->comercial);
        self::assertSame('fusionado', $merged['accion']);
        self::assertSame($created['lead']['id'], $merged['lead']['id']);
        self::assertSame('Lucía', $merged['lead']['nombre'], 'no se pisa lo que ya había');
        self::assertSame('lucia@example.com', $merged['lead']['email'], 'se completa lo que faltaba');
        self::assertSame([2, 5], array_column($merged['lead']['estudios'], 'id'));
    }

    public function testAltaManualSinDatosDeContacto(): void
    {
        $this->expectExceptionMessage('al menos un nombre, un correo o un teléfono');
        $this->app->leads()->create(['ubicacion' => 'Granada', 'origen' => 'WEB'], $this->comercial);
    }

    public function testElOrigenEsObligatorioYSoloUnoDeLosDiez(): void
    {
        $service = $this->app->leads();
        foreach ([[], ['origen' => ''], ['origen' => 'OTRO'], ['origen' => 'FACEBOOK'], ['origen' => ['WEB', 'FERIA']]] as $origin) {
            try {
                $service->create(['nombre' => 'Eva', 'telefono' => '612345678'] + $origin, $this->comercial);
                self::fail('Debía pedir el origen: ' . json_encode($origin));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('origen', $e->getMessage());
            }
        }

        $lead = $service->create(['nombre' => 'Eva', 'telefono' => '612345678', 'origen' => 'APRENDEMAS'], $this->comercial)['lead'];
        self::assertSame(['APRENDEMAS', 'AprendeMás'], [$lead['origen'], $lead['origenLabel']]);
    }

    public function testEditarUnLeadAntiguoConservaSuOrigenYSuCampana(): void
    {
        $service = $this->app->leads();
        // Importado sin origen reconocible y con el formulario de Meta como campaña.
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678', 'origen' => 'teléfono', 'campana' => 'F250454AA | COMM0023']);
        $row = $this->app->leadRepository()->find($id);
        self::assertSame(['OTRO', 'F250454AA | COMM0023'], [$row['origen'], $row['campana']]);

        $edited = $service->update($id, ['nombre' => 'Eva María', 'telefono' => '612345678', 'origen' => 'OTRO'], $this->comercial);
        self::assertSame(['OTRO', 'Sin indicar'], [$edited['origen'], $edited['origenLabel']], 'puede quedarse sin indicar');
        self::assertSame('F250454AA | COMM0023', $this->app->leadRepository()->find($id)['campana'], 'la campaña no se borra al editar');

        $service->update($id, ['nombre' => 'Eva María', 'telefono' => '612345678'], $this->comercial);
        self::assertSame('OTRO', $this->app->leadRepository()->find($id)['origen'], 'sin enviar origen se mantiene');

        self::assertSame('EMAGISTER', $service->update($id, ['nombre' => 'Eva María', 'telefono' => '612345678', 'origen' => 'EMAGISTER'], $this->comercial)['origen']);
        $this->expectExceptionMessage('origen');
        $service->update($id, ['nombre' => 'Eva María', 'telefono' => '612345678', 'origen' => 'OTRO'], $this->comercial);
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

    public function testElWhatsappEsElDeVeronicaConSusDatos(): void
    {
        $text = static fn (array $lead): string => rawurldecode(explode('text=', (string) $lead['whatsappUrl'])[1]);
        $id = $this->lead(['nombre' => 'Candela', 'telefono' => '612345678', 'estudioIds' => [6], 'origen' => 'TUMASTER']);

        self::assertSame(
            "Buenos días, Candela:
"
            . 'Soy Marta, de ESTACIÓN DISEÑO, Escuela Superior de Diseño y Artes Visuales de Granada. Hemos recibido tu solicitud '
            . 'de información sobre nuestro: MÁSTER PROFESIONAL MODA a través de la página TUMASTER.' . "
"
            . 'He intentado contactar contigo por teléfono para explicarte detalles sobre la forma de acceso, metodología, precios '
            . 'y otros aspectos relacionados con la formación. ¿En qué horario te puedo llamar?',
            $text($this->app->leads()->get($id, $this->comercial))
        );

        $this->clock->set('2026-09-15T17:00:00+02:00');
        $sinNombre = $this->lead(['telefono' => '622333444', 'estudioIds' => [1, 2], 'origen' => 'REDES_SOCIALES']);
        self::assertStringStartsWith(
            "Buenas tardes:
Soy Marta, de ESTACIÓN DISEÑO, Escuela Superior de Diseño y Artes Visuales de Granada. Hemos recibido tu solicitud "
            . 'de información sobre nuestros estudios: GRADO DISEÑO MODA y GRADO DISEÑO GRÁFICO a través de nuestras redes sociales.',
            $text($this->app->leads()->get($sinNombre, $this->comercial)),
            'sin nombre, sin la coma; por la tarde, buenas tardes'
        );

        $efp = $this->lead(['nombre' => 'Luis', 'telefono' => '633444555', 'origen' => 'ANO_PASADO']);
        self::assertStringContainsString('Soy Marta, de GRUPO EFP. Hemos recibido tu solicitud de información sobre nuestros estudios.', $text($this->app->leads()->get($efp, $this->comercial)));
    }

    public function testAvisaALaHoraDeLaLlamadaAgendadaAQuienLaAgendo(): void
    {
        $service = $this->app->leads();
        $id = $this->lead(['nombre' => 'Eva', 'telefono' => '612345678', 'estudioIds' => [2]]);
        $service->scheduleCall($id, '2026-09-15T12:30', 'Llamar tras clase', $this->comercial);

        $mine = $service->reminders($this->comercial);
        self::assertSame('2026-09-15T08:00:00Z', $mine['ahora']);
        self::assertSame([['id' => $id, 'nombre' => 'Eva', 'telefono' => '+34 612 34 56 78', 'hora' => '2026-09-15T10:30:00Z', 'mia' => true]], $mine['llamadas']);
        self::assertFalse($service->reminders($this->admin)['llamadas'][0]['mia'], 'las demás ven que hay llamada, pero el aviso es de quien la agendó');

        // Sigue avisando un rato después de la hora si nadie ha llamado, y desaparece al llamar.
        $this->clock->set('2026-09-15T13:00:00+02:00');
        self::assertCount(1, $service->reminders($this->comercial)['llamadas']);
        $service->registerCall($id, 'INTERESADO', null, $this->admin);
        self::assertSame([], $service->reminders($this->comercial)['llamadas']);
        self::assertNull($this->pdo->query("SELECT agendada_por FROM leads WHERE id = {$id}")->fetchColumn());
    }
}
