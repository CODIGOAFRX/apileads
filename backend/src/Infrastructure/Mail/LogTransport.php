<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

use Leads\Domain\Mail\MailTransport;
use Leads\Domain\Mail\Message;

/** Transporte para desarrollo: escribe los correos en un fichero en lugar de enviarlos. */
final class LogTransport implements MailTransport
{
    /** @var list<Message> */
    public array $sent = [];

    public function __construct(private readonly ?string $file = null)
    {
    }

    public function send(Message $message): void
    {
        $this->sent[] = $message;
        if ($this->file === null) {
            return;
        }
        $attachments = array_map(static fn ($a): string => $a->name, $message->attachments);
        $entry = sprintf(
            "=== %s\nDe: %s\nPara: %s <%s>\nAsunto: %s\nAdjuntos: %s\n\n%s\n\n",
            gmdate('c'),
            $message->from ?? '(remitente general)',
            $message->toName,
            $message->to,
            $message->subject,
            $attachments === [] ? '—' : implode(', ', $attachments),
            $message->text()
        );
        @mkdir(dirname($this->file), 0775, true);
        file_put_contents($this->file, $entry, FILE_APPEND | LOCK_EX);
    }
}
