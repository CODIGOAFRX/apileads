<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

use Leads\Domain\Mail\MailException;
use Leads\Domain\Mail\MailTransport;
use Leads\Domain\Mail\Message;

/**
 * Cliente SMTP mínimo (SSL implícito en 465 o STARTTLS en 587) con AUTH LOGIN.
 * La conexión llega de una factoría para poder probar el diálogo sin red.
 */
final class SmtpTransport implements MailTransport
{
    public const ENCRYPTIONS = ['ssl', 'tls', 'none'];

    /** @var \Closure(): SmtpConnection */
    private \Closure $connect;

    public function __construct(
        private readonly MimeBuilder $mime,
        private readonly SmtpSettings $settings,
        ?callable $connect = null,
    ) {
        $this->connect = $connect !== null
            ? \Closure::fromCallable($connect)
            : fn (): SmtpConnection => new SocketSmtpConnection(
                $settings->host,
                $settings->port,
                $settings->encryption === 'ssl',
                $settings->timeout,
                $settings->verifyPeer,
            );
    }

    public function send(Message $message): void
    {
        $this->deliver($message->to, $this->mime->raw($message, $this->domain()));
    }

    /** Envía un mensaje ya construido (ver MimeBuilder::raw) a $to. */
    public function deliver(string $to, string $raw): void
    {
        $this->session(function (SmtpConnection $smtp) use ($to, $raw): void {
            $this->command($smtp, "MAIL FROM:<{$this->clean($this->settings->fromEmail)}>", [250]);
            $this->command($smtp, "RCPT TO:<{$this->clean($to)}>", [250, 251]);
            $this->command($smtp, 'DATA', [354]);
            $this->command($smtp, $this->payload($raw) . "\r\n.", [250], 'DATA');
        });
    }

    /** Comprueba conexión, cifrado y usuario/contraseña sin enviar nada. */
    public function verify(): void
    {
        $this->session(static function (): void {
        });
    }

    /** Saludo, cifrado y autenticación; después $work y, al final, QUIT. */
    private function session(callable $work): void
    {
        $smtp = ($this->connect)();
        try {
            $domain = $this->domain();
            $this->expect($smtp, [220]);
            $this->command($smtp, "EHLO {$domain}", [250]);

            if ($this->settings->encryption === 'tls') {
                $this->command($smtp, 'STARTTLS', [220]);
                $smtp->enableTls();
                $this->command($smtp, "EHLO {$domain}", [250]);
            }

            if ($this->settings->username !== '') {
                $this->command($smtp, 'AUTH LOGIN', [334]);
                $this->command($smtp, base64_encode($this->settings->username), [334], '(usuario)');
                $this->command($smtp, base64_encode($this->settings->password), [235], '(contraseña)');
            }

            $work($smtp);

            try {
                $this->command($smtp, 'QUIT', [221]);
            } catch (MailException) {
                // El mensaje ya está aceptado; un QUIT fallido no es un error de envío.
            }
        } finally {
            $smtp->close();
        }
    }

    private function domain(): string
    {
        return substr((string) strrchr($this->settings->fromEmail, '@'), 1) ?: 'localhost';
    }

    private function payload(string $raw): string
    {
        $data = str_replace(["\r\n", "\n"], ["\n", "\r\n"], rtrim($raw, "\r\n"));

        // Transparencia SMTP: las líneas que empiezan por punto se duplican.
        return (string) preg_replace('/^\./m', '..', $data);
    }

    private function command(SmtpConnection $smtp, string $line, array $expected, ?string $label = null): void
    {
        $smtp->write($line . "\r\n");
        try {
            $this->expect($smtp, $expected);
        } catch (MailException $e) {
            $shown = $label ?? $line;
            throw new MailException("El servidor de correo rechazó {$shown}: " . $e->getMessage(), 0, $e);
        }
    }

    private function expect(SmtpConnection $smtp, array $expected): void
    {
        $response = '';
        while (($line = $smtp->readLine()) !== null) {
            $response .= $line;
            // Respuesta multilínea: "250-..." continúa, "250 ..." termina.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if (!in_array((int) substr($response, 0, 3), $expected, true)) {
            throw new MailException(trim($response) === '' ? 'sin respuesta' : trim($response));
        }
    }

    private function clean(string $email): string
    {
        return str_replace(["\r", "\n", '<', '>'], '', $email);
    }
}
