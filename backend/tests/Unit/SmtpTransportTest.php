<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Domain\Mail\MailException;
use Leads\Domain\Mail\Message;
use Leads\Infrastructure\Mail\MimeBuilder;
use Leads\Infrastructure\Mail\SmtpConnection;
use Leads\Infrastructure\Mail\SmtpSettings;
use Leads\Infrastructure\Mail\SmtpTransport;
use PHPUnit\Framework\TestCase;

final class SmtpTransportTest extends TestCase
{
    public function testDialogoCompletoConStarttlsYAutenticacion(): void
    {
        $server = new FakeSmtpConnection([
            "220 mail.test ESMTP\r\n",
            "250-mail.test\r\n", "250 STARTTLS\r\n",
            "220 Ready\r\n",
            "250-mail.test\r\n", "250 AUTH LOGIN\r\n",
            "334 VXNlcm5hbWU6\r\n", "334 UGFzc3dvcmQ6\r\n", "235 OK\r\n",
            "250 OK\r\n", "250 OK\r\n", "354 Go\r\n", "250 Queued\r\n", "221 Bye\r\n",
        ]);

        $this->transport($server)->send(new Message('lucia@example.com', 'Lucía', 'Información · Máster', "<p>Hola</p>\n<p>.punto</p>"));

        self::assertTrue($server->tls);
        self::assertTrue($server->closed);
        self::assertSame('EHLO estaciondiseno.es', $server->written[0]);
        self::assertSame('STARTTLS', $server->written[1]);
        self::assertSame(base64_encode('usuario'), $server->written[4]);
        self::assertSame('MAIL FROM:<info@estaciondiseno.es>', $server->written[6]);
        self::assertSame('RCPT TO:<lucia@example.com>', $server->written[7]);
        $data = $server->written[9];
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Información · Máster') . '?=', $data);
        self::assertStringContainsString("Content-Type: multipart/alternative", $data);
        self::assertStringEndsWith("\r\n.", $data);
        self::assertSame('QUIT', $server->written[10]);
    }

    public function testInformaDelRechazoSinMostrarLaContrasena(): void
    {
        $server = new FakeSmtpConnection([
            "220 hi\r\n", "250 hi\r\n", "220 go\r\n", "250 hi\r\n",
            "334 u\r\n", "334 p\r\n", "535 Authentication failed\r\n",
        ]);

        try {
            $this->transport($server)->send(new Message('a@example.com', '', 'x', 'y'));
            self::fail('Debía fallar');
        } catch (MailException $e) {
            self::assertStringContainsString('(contraseña)', $e->getMessage());
            self::assertStringContainsString('535', $e->getMessage());
            self::assertStringNotContainsString(base64_encode('secreto'), $e->getMessage());
        }
        self::assertTrue($server->closed);
    }

    public function testEvitaInyeccionDeCabecerasEnElAsunto(): void
    {
        $mime = (new MimeBuilder('info@estaciondiseno.es', 'Estación'))->build(
            new Message("a@example.com>\r\nBcc: x@y.z", 'A', "Hola\r\nBcc: x@y.z", '<p>x</p>'),
            'estaciondiseno.es'
        );

        self::assertStringNotContainsString("\r\nBcc", $mime['headers']['Subject']);
        self::assertStringNotContainsString("\n", $mime['headers']['To']);
    }

    private function transport(FakeSmtpConnection $server): SmtpTransport
    {
        return new SmtpTransport(
            new MimeBuilder('info@estaciondiseno.es', 'Estación Diseño'),
            new SmtpSettings('smtp.test', 587, 'tls', 'usuario', 'secreto', 'info@estaciondiseno.es'),
            static fn () => $server,
        );
    }
}

final class FakeSmtpConnection implements SmtpConnection
{
    public array $written = [];
    public bool $tls = false;
    public bool $closed = false;

    public function __construct(private array $responses)
    {
    }

    public function write(string $line): void
    {
        $this->written[] = substr($line, 0, -2);
    }

    public function readLine(): ?string
    {
        return array_shift($this->responses);
    }

    public function enableTls(): void
    {
        $this->tls = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
