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
        self::assertStringStartsWith('<p>Hola:</p>', $preview['html'], 'sin nombre no queda "Hola :"');
        self::assertStringContainsString('<h3 style="margin:24px 0 8px">Grado Diseño Moda</h3><p>Bloque moda</p>', $preview['html']);
        self::assertStringContainsString('Marta Comercial', $preview['html']);
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
        self::assertCount(3, $templates);
        self::assertSame([null, 'sin-estudio', null], array_column($templates, 'clave'));
        self::assertSame([null, null, 3], array_column($templates, 'estudioId'));
        self::assertSame('Audiovisual 2', $templates[2]['asunto']);

        $mail->deleteTemplate(3);
        self::assertCount(2, $mail->templates()['plantillas']);
    }
}
