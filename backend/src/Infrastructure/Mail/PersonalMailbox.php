<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

use Leads\Domain\Mail\MailException;
use Leads\Domain\Mail\MailTransport;
use Leads\Domain\Mail\Message;

/**
 * Buzón propio de una comercial: envía por su SMTP y, si está configurado,
 * deja una copia en su carpeta de Enviados por IMAP. Si la copia falla el
 * correo ya ha salido, así que no es un error: queda como aviso.
 */
final class PersonalMailbox implements MailTransport
{
    /** Aviso del último envío (p. ej. no se pudo guardar la copia). */
    public ?string $warning = null;

    public function __construct(
        private readonly MimeBuilder $mime,
        private readonly SmtpTransport $smtp,
        private readonly string $fromEmail,
        private readonly ?ImapClient $imap = null,
    ) {
    }

    public function send(Message $message): void
    {
        $this->warning = null;
        $raw = $this->mime->raw($message, substr((string) strrchr($this->fromEmail, '@'), 1) ?: 'localhost');
        $this->smtp->deliver($message->to, $raw);

        if ($this->imap !== null) {
            try {
                $this->imap->appendToSent($raw);
            } catch (MailException $e) {
                $this->warning = 'Enviado, pero no se pudo guardar la copia en Enviados: ' . $e->getMessage();
            }
        }
    }

    /**
     * Prueba el envío (SMTP) y la copia (IMAP) sin mandar nada.
     *
     * @return array{smtp: array{ok: bool, mensaje: string}, imap: ?array{ok: bool, mensaje: string}}
     */
    public function verify(): array
    {
        $result = ['smtp' => self::check(function (): string {
            $this->smtp->verify();
            return 'Conexión y contraseña correctas';
        }), 'imap' => null];

        if ($this->imap !== null) {
            $result['imap'] = self::check(fn (): string => 'Las copias se guardarán en «' . $this->imap->verify() . '»');
        }

        return $result;
    }

    /** @return array{ok: bool, mensaje: string} */
    private static function check(callable $probe): array
    {
        try {
            return ['ok' => true, 'mensaje' => $probe()];
        } catch (MailException $e) {
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        }
    }
}
