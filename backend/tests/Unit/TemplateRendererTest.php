<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Application\MailService;
use Leads\Domain\Mail\Message;
use Leads\Domain\Mail\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    public function testSustituyeVariablesEscapandoHtml(): void
    {
        $html = (new TemplateRenderer())->render(
            '<p>Hola {{ nombre }}</p>{{bloques_estudios}}{{desconocida}}',
            ['nombre' => '<b>Ana</b>', 'bloques_estudios' => '<h3>Máster</h3>'],
            true
        );

        self::assertSame('<p>Hola &lt;b&gt;Ana&lt;/b&gt;</p><h3>Máster</h3>', $html);
    }

    public function testElAsuntoNoAdmiteSaltosDeLinea(): void
    {
        self::assertSame('Info para Ana Bcc: x', (new TemplateRenderer())->subject('Info para {{nombre}}', ['nombre' => "Ana\r\nBcc: x"]));
    }

    public function testListaLegible(): void
    {
        self::assertSame('A, B y C', TemplateRenderer::humanList(['A', 'B', 'C']));
        self::assertSame('A', TemplateRenderer::humanList(['A']));
        self::assertSame('', TemplateRenderer::humanList([]));
    }

    public function testLimpiaHtmlPeligroso(): void
    {
        $clean = MailService::sanitize('<p onclick="x()">Hola</p><script>alert(1)</script><a href="javascript:alert(1)">x</a><iframe src="y"></iframe>');

        self::assertSame('<p>Hola</p><a href="#alert(1)">x</a>', $clean);
    }

    public function testVersionEnTextoPlano(): void
    {
        $message = new Message('a@b.es', '', 's', '<p>Hola&nbsp;Ana</p><p>Adiós<br>equipo</p>');

        self::assertSame("Hola\u{00A0}Ana\n\nAdiós\nequipo", $message->text());
    }
}
