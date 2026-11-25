<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

use Leads\Domain\Mail\MailException;
use Leads\Domain\Mail\MailTransport;
use Leads\Domain\Mail\Message;

/** Usa la función mail() de PHP (sendmail del servidor Plesk). */
final class NativeMailTransport implements MailTransport
{
    public function __construct(private readonly MimeBuilder $mime, private readonly string $fromEmail)
    {
    }

    public function send(Message $message): void
    {
        $domain = substr((string) strrchr($this->fromEmail, '@'), 1) ?: 'localhost';
        $mime = $this->mime->build($message, $domain);
        $headers = $mime['headers'];
        $to = $headers['To'];
        $subject = $headers['Subject'];
        unset($headers['To'], $headers['Subject']);

        $envelope = filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL) ? '-f' . $this->fromEmail : '';
        $ok = mail($to, $subject, $mime['body'], $headers, $envelope);
        if (!$ok) {
            throw new MailException('El servidor rechazó el envío (mail())');
        }
    }
}
