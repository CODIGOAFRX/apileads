<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

use Leads\Core\HttpException;

final class MailServiceTest extends DatabaseTestCase
{
    public function testEnviaLaPlantillaDelEstudioSiSoloHayUno(): void
    {
        $mail = $this->app->mail();
        $mail->saveTemplate(5, 'Tu máster, {{nombre}}', '<p>Hola {{nombre}}, info del {{estudio}}.</p><script>x</script>');
        $id = $this->lead(['nombre' => 'Eva', 'email' => 'eva@example.com', 'estudioIds' => [5]]);

        $lead = $mail->sendInfo($id, $this->comercial);

        self::assertCount(1, $this->mailer->sent);
        $message = $this->mailer->sent[0];
        self::assertSame('eva@example.com', $message->to);
        self::assertSame('Tu máster, Eva', $message->subject);
        self::assertSame('<p>Hola Eva, info del Máster Ilustración.</p>', $message->html);
        self::assertSame('marta@test.es', $message->replyTo);
        self::assertSame('CORREO', $lead['historial'][0]['tipo']);
    }

    public function testConVariosEstudiosUsaLaGenericaConLosBloques(): void
    {
        $mail = $this->app->mail();
        $mail->saveTemplate(1, 'no se usa', '<p>Bloque moda</p>');
        $id = $this->lead(['email' => 'anon@example.com', 'estudioIds' => [1, 2]]);

        $preview = $mail->preview($id, $this->comercial);

        self::assertSame('Información sobre Grado Diseño Moda y Grado Diseño Gráfico · Estación Diseño', $preview['asunto']);
        self::assertStringStartsWith('<p>Buenos días:</p>', $preview['html'], 'sin nombre no queda "Buenos días, :"');
        self::assertStringContainsString('Soy Marta, del departamento', $preview['html']);
        // Sin marcas de bloque: la plantilla entera con el nombre del estudio como título.
        self::assertStringContainsString('<h3 style="margin:24px 0 8px">Grado Diseño Moda</h3><p>Bloque moda</p>', $preview['html']);
        // Con marcas: solo lo de dentro (sin su saludo ni su firma).
        self::assertStringContainsString('GRADO EN DISEÑO GRÁFICO</h2>', $preview['html']);
        self::assertStringNotContainsString('sobre nuestro <strong>Grado en Diseño Gráfico', $preview['html']);
        self::assertSame(1, substr_count($preview['html'], 'Dpto. de Información y Asesoramiento'));
        self::assertStringNotContainsString('{{', $preview['html']);
        // Un dossier por estudio.
        self::assertSame(
            ['Dossier Grado Diseño de Moda 26-27.pdf', 'Dossier Grado Diseño Gráfico 26-27.pdf'],
            array_column($preview['adjuntos'], 'nombre'),
        );
    }

    public function testElCorreoDeUnEstudioLlevaSuTextoYSuDossier(): void
    {
        $mail = $this->app->mail();
        $id = $this->lead(['nombre' => 'Eva', 'email' => 'eva@example.com', 'estudioIds' => [2]]);

        $preview = $mail->preview($id, $this->comercial);
        self::assertNull($preview['de'], 'sin buzón propio sale del remitente general');
        self::assertSame('Información: Grado en Diseño Gráfico · Estación Diseño', $preview['asunto']);
        self::assertStringStartsWith('<p>Buenos días, Eva:</p>', $preview['html']);
        self::assertStringContainsString('Soy Marta, del departamento de información', $preview['html']);
        self::assertStringContainsString('<strong>Marta Comercial</strong><br>Dpto. de Información y Asesoramiento', $preview['html']);
        self::assertStringNotContainsString('{{', $preview['html']);

        $mail->sendInfo($id, $this->comercial);
        $message = $this->mailer->sent[0];
        self::assertCount(1, $message->attachments);
        self::assertSame('Dossier Grado Diseño Gráfico 26-27.pdf', $message->attachments[0]->name);
        self::assertSame('application/pdf', $message->attachments[0]->mimeType);
        self::assertStringStartsWith('%PDF', $message->attachments[0]->content);
        self::assertSame(filesize(dirname(__DIR__, 2) . '/database/correos/dossier-grado-diseno-grafico.pdf'), strlen($message->attachments[0]->content));

        $lead = $this->app->leads()->get($id, $this->comercial);
        self::assertSame(['Dossier Grado Diseño Gráfico 26-27.pdf'], $lead['historial'][0]['detalle']['adjuntos']);
    }

    public function testLosCuatroEstudiosTienenTextoYDossier(): void
    {
        $templates = array_column($this->app->mail()->templates()['plantillas'], null, 'estudioId');

        foreach ([1 => 'Moda', 2 => 'Gráfico', 4 => 'Ilustración', 5 => 'Máster'] as $studyId => $word) {
            self::assertStringContainsString($word, $templates[$studyId]['asunto']);
            self::assertStringContainsString('{{inicio_bloque}}', $templates[$studyId]['cuerpoHtml']);
            self::assertStringEndsWith('.pdf', $templates[$studyId]['adjunto']['nombre']);
            self::assertGreaterThan(500_000, $templates[$studyId]['adjunto']['tamano']);
        }
    }

    public function testSubirCambiarYQuitarElDossier(): void
    {
        $mail = $this->app->mail();
        $mail->saveTemplate(3, 'Audiovisual', '<p>x</p>');
        $template = array_column($mail->templates()['plantillas'], null, 'estudioId')[3];
        self::assertNull($template['adjunto']);

        $file = (string) tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, "%PDF-1.4\nprueba");
        $mail->attachToTemplate($template['id'], $file, '../Dossier "Audiovisual".pdf');
        $mail->attachToTemplate($template['id'], $file, 'Dossier Audiovisual 26-27.pdf');
        $templates = array_column($mail->templates()['plantillas'], null, 'estudioId');
        self::assertSame('Dossier Audiovisual 26-27.pdf', $templates[3]['adjunto']['nombre']);
        self::assertSame("%PDF-1.4\nprueba", $mail->attachment($templates[3]['adjunto']['id'])['contenido']);
        self::assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM adjunto')->fetchColumn(), 'el sustituido se borra');

        $mail->detachFromTemplate($template['id']);
        self::assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM adjunto')->fetchColumn());

        file_put_contents($file, 'no soy un pdf');
        try {
            $mail->attachToTemplate($template['id'], $file, 'dossier.pdf');
            self::fail('Debía rechazar un PDF falso');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('PDF', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $mail->attachToTemplate($template['id'], $file, 'virus.exe');
    }

    public function testEnviaDesdeElBuzonDeLaComercial(): void
    {
        $accounts = $this->app->mailAccounts();
        $userId = (int) $this->comercial['id'];
        $saved = $accounts->save($userId, [
            'email' => 'Marta@EstacionDiseno.es', 'password' => 'clave-del-buzon',
            'smtpHost' => 'estaciondiseno.es', 'smtpPuerto' => 465,
            'imapHost' => 'estaciondiseno.es', 'imapPuerto' => 993, 'guardarEnviados' => true,
        ]);
        self::assertSame('marta@estaciondiseno.es', $saved['email']);
        self::assertSame('marta@estaciondiseno.es', $saved['usuario'], 'el usuario es la dirección si no se indica');
        self::assertSame('ssl', $saved['smtpCifrado'], '465 = SSL');
        self::assertArrayNotHasKey('password', $saved);
        $stored = (string) $this->pdo->query('SELECT password_cifrada FROM cuenta_correo')->fetchColumn();
        self::assertStringNotContainsString('clave-del-buzon', $stored);

        // Sin contraseña se conserva la guardada.
        $accounts->save($userId, ['email' => 'marta@estaciondiseno.es', 'smtpHost' => 'estaciondiseno.es', 'smtpPuerto' => 587]);
        self::assertSame($stored, (string) $this->pdo->query('SELECT password_cifrada FROM cuenta_correo')->fetchColumn());
        self::assertSame('tls', $accounts->get($userId)['smtpCifrado']);
        self::assertNotNull($accounts->mailbox($userId));

        $mail = $this->app->mail();
        $id = $this->lead(['nombre' => 'Eva', 'email' => 'eva@example.com', 'estudioIds' => [5]]);
        self::assertSame('marta@estaciondiseno.es', $mail->preview($id, $this->comercial)['de']);
        $mail->sendInfo($id, $this->comercial);

        $message = $this->mailer->sent[0];
        self::assertSame('marta@estaciondiseno.es', $message->from);
        self::assertSame('Marta Comercial', $message->fromName);
        self::assertNull($message->replyTo, 'las respuestas llegan a su propio buzón');
        self::assertSame('marta@estaciondiseno.es', $this->pdo->query('SELECT remitente FROM envio_correo')->fetchColumn());

        // El administrador (sin buzón) sigue usando el general.
        self::assertNull($mail->preview($id, $this->admin)['de']);

        $accounts->delete($userId);
        self::assertNull($accounts->get($userId));
    }

    public function testValidaElBuzon(): void
    {
        $accounts = $this->app->mailAccounts();
        $userId = (int) $this->comercial['id'];
        $valid = ['email' => 'marta@estaciondiseno.es', 'password' => 'x', 'smtpHost' => 'estaciondiseno.es'];

        foreach ([
            'Indica una dirección' => ['email' => 'marta'],
            'contraseña del buzón' => ['password' => ''],
            'servidor de correo saliente' => ['smtpHost' => 'https://estaciondiseno.es'],
            'puerto del servidor saliente' => ['smtpPuerto' => 70000],
            'servidor de correo entrante' => ['guardarEnviados' => true],
            'carpeta de Enviados' => ['carpetaEnviados' => 'Sent" INBOX'],
        ] as $error => $change) {
            try {
                $accounts->save($userId, $change + $valid);
                self::fail("Debía fallar: {$error}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($error, $e->getMessage());
            }
        }
        $this->expectException(HttpException::class);
        $accounts->save(999, $valid);
    }

    public function testSinEstudioUsaSuPropiaPlantilla(): void
    {
        $mail = $this->app->mail();
        $id = $this->lead(['nombre' => 'Eva', 'email' => 'eva@example.com']);

        $preview = $mail->preview($id, $this->comercial);
        self::assertSame('Información sobre Estación Diseño', $preview['asunto']);
        self::assertStringContainsString('qué te gustaría estudiar', $preview['html']);
        self::assertNull($preview['ultimoEnvio']);

        $mail->saveTemplate(null, 'Hola {{nombre}}', '<p>Texto definitivo</p>', 'sin-estudio');
        $mail->sendInfo($id, $this->comercial);
        self::assertSame('Hola Eva', $this->mailer->sent[0]->subject);
        self::assertSame('<p>Texto definitivo</p>', $this->mailer->sent[0]->html);
        self::assertSame('2026-09-15T08:00:00Z', $mail->preview($id, $this->comercial)['ultimoEnvio']);

        // La genérica no se ha tocado.
        $generic = $mail->templates()['plantillas'][0];
        self::assertNull($generic['clave']);
        self::assertSame('Información sobre {{estudios}} · Estación Diseño', $generic['asunto']);

        $this->expectException(\InvalidArgumentException::class);
        $mail->saveTemplate(null, 'x', '<p>x</p>', 'inventada');
    }

    public function testSePuedeRetocarUnCorreoConcretoAntesDeEnviarlo(): void
    {
        $mail = $this->app->mail();
        $id = $this->lead(['nombre' => 'Eva', 'email' => 'eva@example.com', 'estudioIds' => [5]]);

        $lead = $mail->sendInfo(
            $id,
            $this->comercial,
            "Última semana\npara matricularte",
            '<p>Hola Eva:</p><p>Te recordamos que la matrícula cierra el viernes.</p><script>alert(1)</script><a href="javascript:x" onclick="y">web</a>',
        );

        $message = $this->mailer->sent[0];
        self::assertSame('Última semana para matricularte', $message->subject, 'sin saltos de línea en el asunto');
        self::assertSame('<p>Hola Eva:</p><p>Te recordamos que la matrícula cierra el viernes.</p><a href="#x">web</a>', $message->html);
        self::assertSame('Información enviada (retocada): Última semana para matricularte', $lead['historial'][0]['resumen']);
        self::assertStringNotContainsString('cierra el viernes', $mail->preview($id, $this->comercial)['html'], 'la plantilla no cambia');

        $this->clock->advance('+3 minutes');
        $this->expectExceptionMessage('sin texto');
        $mail->sendInfo($id, $this->comercial, null, '<p><br></p>');
    }

    public function testEvitaDoblesEnviosYCorreosNoValidos(): void
    {
        $mail = $this->app->mail();
        $id = $this->lead(['nombre' => 'Eva', 'email' => 'eva@example.com']);
        $mail->sendInfo($id, $this->comercial);

        try {
            $mail->sendInfo($id, $this->comercial);
            self::fail('Debía bloquear el reenvío inmediato');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status());
        }

        $this->clock->advance('+3 minutes');
        $mail->sendInfo($id, $this->comercial);
        self::assertCount(2, $this->mailer->sent);

        $bad = $this->lead(['nombre' => 'Mal', 'email' => 'mal@', 'telefono' => '600000000']);
        $this->expectException(\InvalidArgumentException::class);
        $mail->sendInfo($bad, $this->comercial);
    }

    public function testListaYBorraPlantillas(): void
    {
        $mail = $this->app->mail();
        $mail->saveTemplate(3, 'Audiovisual', '<p>x</p>');
        $mail->saveTemplate(3, 'Audiovisual 2', '<p>y</p>');

        $templates = $mail->templates()['plantillas'];
        self::assertCount(7, $templates);
        self::assertSame([null, 'sin-estudio', null, null, null, null, null], array_column($templates, 'clave'));
        self::assertSame([null, null, 1, 2, 3, 4, 5], array_column($templates, 'estudioId'));
        self::assertSame('Audiovisual 2', $templates[4]['asunto']);

        $mail->deleteTemplate(3);
        self::assertCount(6, $mail->templates()['plantillas']);
    }
}
