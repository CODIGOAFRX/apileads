<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Domain\Mail\Attachment;
use Leads\Domain\Mail\MailException;
use Leads\Domain\Mail\Message;
use Leads\Infrastructure\Mail\ImapClient;
use Leads\Infrastructure\Mail\ImapSettings;
use Leads\Infrastructure\Mail\MimeBuilder;
use Leads\Infrastructure\Mail\PersonalMailbox;
use Leads\Infrastructure\Mail\SmtpConnection;
use Leads\Infrastructure\Mail\SmtpSettings;
use Leads\Infrastructure\Mail\SmtpTransport;
use Leads\Infrastructure\Security\SecretBox;
use PHPUnit\Framework\TestCase;

final class PersonalMailboxTest extends TestCase
{
    private const SMTP_OK = [
        "220 estaciondiseno.es ESMTP\r\n", "250 hola\r\n",
        "334 VXNlcm5hbWU6\r\n", "334 UGFzc3dvcmQ6\r\n", "235 OK\r\n",
        "250 OK\r\n", "250 OK\r\n", "354 Go\r\n", "250 Queued\r\n", "221 Bye\r\n",
    ];

    public function testEnviaPorSmtpYGuardaLaMismaCopiaEnEnviados(): void
    {
        $smtp = new ScriptedConnection(self::SMTP_OK);
        $imap = new ScriptedConnection([
            "* OK Dovecot ready.\r\n",
            "A1 OK Logged in\r\n",
            "* LIST (\\HasNoChildren) \".\" \"INBOX\"\r\n",
            "* LIST (\\HasNoChildren \\Sent) \".\" \"INBOX.Sent\"\r\n",
            "A2 OK List completed\r\n",
            "+ OK\r\n",
            "A3 OK Append completed\r\n",
            "* BYE\r\n", "A4 OK Logout\r\n",
        ]);

        $mailbox = $this->mailbox($smtp, $imap);
        $mailbox->send(new Message('eva@example.com', 'Eva', 'Información', '<p>Hola</p>', from: 'marta@estaciondiseno.es', fromName: 'Marta López'));

        self::assertNull($mailbox->warning);
        self::assertSame('MAIL FROM:<marta@estaciondiseno.es>', $smtp->written[4]);
        self::assertSame('A1 LOGIN "marta@estaciondiseno.es" "cl\\"ave"', $imap->written[0]);
        self::assertSame('A2 LIST "" "*"', $imap->written[1]);
        self::assertMatchesRegularExpression('/^A3 APPEND "INBOX\.Sent" \(\\\\Seen\) \{(\d+)\}$/', $imap->written[2]);

        // La copia es exactamente lo que salió por SMTP (mismo Message-ID) y mide lo anunciado.
        preg_match('/\{(\d+)\}$/', $imap->written[2], $m);
        $copy = $imap->written[3];
        self::assertSame((int) $m[1], strlen($copy));
        self::assertStringContainsString('From: =?UTF-8?B?' . base64_encode('Marta López') . '?= <marta@estaciondiseno.es>', $copy);
        preg_match('/Message-ID: (\S+)/', $copy, $id);
        self::assertStringContainsString('Message-ID: ' . $id[1], $smtp->written[7]);
        self::assertTrue($smtp->closed);
        self::assertTrue($imap->closed);
    }

    public function testSiNoSePuedeGuardarLaCopiaElCorreoYaHaSalido(): void
    {
        $imap = new ScriptedConnection(["* OK ready\r\n", "A1 NO [AUTHENTICATIONFAILED] Authentication failed.\r\n"]);
        $mailbox = $this->mailbox(new ScriptedConnection(self::SMTP_OK), $imap);

        $mailbox->send(new Message('eva@example.com', 'Eva', 'x', '<p>x</p>'));

        self::assertStringContainsString('no se pudo guardar la copia en Enviados', $mailbox->warning);
        self::assertStringContainsString('AUTHENTICATIONFAILED', $mailbox->warning);
        self::assertStringNotContainsString('cl"ave', $mailbox->warning, 'nunca se muestra la contraseña');
    }

    public function testBuscaEnviadosPorNombreSiElServidorNoLaMarca(): void
    {
        $imap = new ScriptedConnection([
            "* OK ready\r\n", "A1 OK\r\n",
            "* LIST (\\HasChildren) \"/\" INBOX\r\n",
            "* LIST (\\HasNoChildren) \"/\" \"Elementos enviados\"\r\n",
            "A2 OK\r\n", "A3 OK\r\n",
        ]);

        self::assertSame('Elementos enviados', $this->imap($imap)->verify());
        self::assertSame('A3 LOGOUT', $imap->written[2]);
    }

    public function testLaCarpetaIndicadaAManoNoSeBusca(): void
    {
        $imap = new ScriptedConnection(["* OK ready\r\n", "A1 OK\r\n", "A2 OK\r\n"]);

        self::assertSame('INBOX.Enviados', $this->imap($imap, 'INBOX.Enviados')->verify());
        self::assertCount(2, $imap->written, 'solo LOGIN y LOGOUT');
    }

    public function testSinCarpetaDeEnviadosAvisaDeQueSeIndiqueAMano(): void
    {
        $imap = new ScriptedConnection(["* OK ready\r\n", "A1 OK\r\n", "* LIST () \".\" INBOX\r\n", "A2 OK\r\n"]);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('indica su nombre a mano');
        $this->imap($imap)->verify();
    }

    public function testLaPruebaDeConexionInformaDeCadaServidor(): void
    {
        $smtp = new ScriptedConnection(["220 hola\r\n", "250 hola\r\n", "334 u\r\n", "334 p\r\n", "535 5.7.8 Error: authentication failed\r\n"]);
        $imap = new ScriptedConnection(["* OK ready\r\n", "A1 OK\r\n", "* LIST (\\Sent) \".\" \"INBOX.Sent\"\r\n", "A2 OK\r\n", "A3 OK\r\n"]);

        $result = $this->mailbox($smtp, $imap)->verify();

        self::assertFalse($result['smtp']['ok']);
        self::assertStringContainsString('(contraseña)', $result['smtp']['mensaje']);
        self::assertStringContainsString('535', $result['smtp']['mensaje']);
        self::assertTrue($result['imap']['ok']);
        self::assertStringContainsString('INBOX.Sent', $result['imap']['mensaje']);
        self::assertNotContains('MAIL FROM:<marta@estaciondiseno.es>', $smtp->written, 'la prueba no envía nada');
    }

    public function testMimeConDossierAdjunto(): void
    {
        $raw = (new MimeBuilder('info@estaciondiseno.es', 'Estación Diseño'))->raw(
            new Message('eva@example.com', 'Eva', 'Info', '<p>Hola</p>', attachments: [
                new Attachment('Dossier Máster "Ilustración".pdf', 'application/pdf', "%PDF-1.7\x00\xFF binario"),
            ]),
            'estaciondiseno.es',
        );

        self::assertStringContainsString('Content-Type: multipart/mixed;', $raw);
        self::assertStringContainsString('From: =?UTF-8?B?' . base64_encode('Estación Diseño') . '?= <info@estaciondiseno.es>', $raw);
        self::assertStringContainsString('Content-Type: multipart/alternative;', $raw);
        self::assertStringContainsString("filename*=UTF-8''" . rawurlencode('Dossier Máster Ilustración.pdf'), $raw);
        self::assertStringContainsString(base64_encode("%PDF-1.7\x00\xFF binario"), $raw);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $raw), 'todo con CRLF');
        self::assertStringEndsWith("--\r\n", $raw);
    }

    public function testNombreDelRemitenteConComa(): void
    {
        $mime = (new MimeBuilder('info@estaciondiseno.es', ''))->build(
            new Message('eva@example.com', 'Eva', 'x', 'y', from: 'ana@estaciondiseno.es', fromName: 'Garcia, Ana'),
            'estaciondiseno.es',
        );

        self::assertSame('"Garcia, Ana" <ana@estaciondiseno.es>', $mime['headers']['From']);
    }

    public function testLasContrasenasSeGuardanCifradas(): void
    {
        $box = new SecretBox('una-clave-larga-de-prueba-123456');
        $encrypted = $box->encrypt('contraseña del buzón');

        self::assertStringStartsWith('v1:', $encrypted);
        self::assertStringNotContainsString('buzón', $encrypted);
        self::assertNotSame($encrypted, $box->encrypt('contraseña del buzón'), 'cada cifrado es distinto');
        self::assertSame('contraseña del buzón', $box->decrypt($encrypted));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vuelve a escribirla');
        (new SecretBox('otra-clave-distinta-de-prueba-99'))->decrypt($encrypted);
    }

    private function mailbox(ScriptedConnection $smtp, ScriptedConnection $imap): PersonalMailbox
    {
        $mime = new MimeBuilder('marta@estaciondiseno.es', '');
        $transport = new SmtpTransport(
            $mime,
            new SmtpSettings('estaciondiseno.es', 465, 'ssl', 'marta@estaciondiseno.es', 'cl"ave', 'marta@estaciondiseno.es'),
            static fn () => $smtp,
        );

        return new PersonalMailbox($mime, $transport, 'marta@estaciondiseno.es', $this->imap($imap));
    }

    private function imap(ScriptedConnection $imap, ?string $folder = null): ImapClient
    {
        return new ImapClient(
            new ImapSettings('estaciondiseno.es', 993, 'ssl', 'marta@estaciondiseno.es', 'cl"ave', $folder),
            static fn () => $imap,
        );
    }
}

/** Servidor de correo simulado: respuestas preparadas y registro de lo escrito (sin el CRLF final). */
final class ScriptedConnection implements SmtpConnection
{
    public array $written = [];
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
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
