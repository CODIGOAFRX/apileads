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
            );
    }

    public function send(Message $message): void
    {
        $connection = ($this->connect)();
        try {
            $this->conversation($connection, $message);
        } finally {
            $connection->close();
        }
    }

    private function conversation(SmtpConnection $smtp, Message $message): void
    {
        $from = $this->settings->fromEmail;
        $domain = substr((string) strrchr($from, '@'), 1) ?: 'localhost';

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

        $this->command($smtp, "MAIL FROM:<{$this->clean($from)}>", [250]);
        $this->command($smtp, "RCPT TO:<{$this->clean($message->to)}>", [250, 251]);
        $this->command($smtp, 'DATA', [354]);
        $this->command($smtp, $this->payload($message, $domain) . "\r\n.", [250], 'DATA');

        try {
            $this->command($smtp, 'QUIT', [221]);
        } catch (MailException) {
            // El mensaje ya está aceptado; un QUIT fallido no es un error de envío.
        }
    }

    private function payload(Message $message, string $domain): string
    {
        $mime = $this->mime->build($message, $domain);
        $data = '';
        foreach ($mime['headers'] as $name => $value) {
            $data .= "{$name}: {$value}\r\n";
        }
        $data .= "\r\n" . $mime['body'];
        $data = str_replace(["\r\n", "\n"], ["\n", "\r\n"], rtrim($data, "\r\n"));

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
