<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

use Leads\Domain\Mail\Attachment;
use Leads\Domain\Mail\Message;

/** Construye el mensaje MIME (texto + HTML y, si los hay, adjuntos) en UTF-8. */
final class MimeBuilder
{
    public function __construct(
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    /** @return array{headers: array<string,string>, body: string} */
    public function build(Message $message, string $domain): array
    {
        $from = $message->from !== null && $message->from !== ''
            ? $this->address($message->from, $message->fromName)
            : $this->address($this->fromEmail, $this->fromName);
        $headers = [
            'Date' => date(DATE_RFC2822),
            'From' => $from,
            'To' => $this->address($message->to, $message->toName),
            'Subject' => $this->encodeHeader($message->subject),
            'Message-ID' => sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain),
            'MIME-Version' => '1.0',
        ];
        if ($message->replyTo !== null && filter_var($message->replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers['Reply-To'] = $message->replyTo;
        }

        [$contentType, $body] = $this->alternative($message);
        if ($message->attachments !== []) {
            $boundary = 'm' . bin2hex(random_bytes(12));
            $mixed = "--{$boundary}\r\nContent-Type: {$contentType}\r\n\r\n{$body}";
            foreach ($message->attachments as $attachment) {
                $mixed .= "--{$boundary}\r\n" . $this->attachmentPart($attachment);
            }
            [$contentType, $body] = ["multipart/mixed; boundary=\"{$boundary}\"", $mixed . "--{$boundary}--\r\n"];
        }
        $headers['Content-Type'] = $contentType;

        return ['headers' => $headers, 'body' => $body];
    }

    /** Mensaje completo (cabeceras + cuerpo) con saltos CRLF, tal cual viaja por SMTP o se guarda por IMAP. */
    public function raw(Message $message, string $domain): string
    {
        $mime = $this->build($message, $domain);
        $data = '';
        foreach ($mime['headers'] as $name => $value) {
            $data .= "{$name}: {$value}\r\n";
        }
        $data .= "\r\n" . $mime['body'];

        return str_replace(["\r\n", "\n"], ["\n", "\r\n"], rtrim($data, "\r\n")) . "\r\n";
    }

    public function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    /** @return array{0: string, 1: string} Content-Type y cuerpo de la parte texto + HTML */
    private function alternative(Message $message): array
    {
        $boundary = 'b' . bin2hex(random_bytes(12));
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($message->text()))
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($this->wrapHtml($message)))
            . "--{$boundary}--\r\n";

        return ["multipart/alternative; boundary=\"{$boundary}\"", $body];
    }

    private function attachmentPart(Attachment $attachment): string
    {
        $name = str_replace(['"', '\\', "\r", "\n"], '', $attachment->name);
        $type = preg_match('#^[\w.+-]+/[\w.+-]+$#', $attachment->mimeType) ? $attachment->mimeType : 'application/octet-stream';
        // Nombre con tildes: palabra codificada (Outlook, Gmail) y RFC 2231 para el resto.
        $quoted = '"' . $this->encodeHeader($name) . '"';

        return "Content-Type: {$type}; name={$quoted}\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename={$quoted}; filename*=UTF-8''" . rawurlencode($name) . "\r\n\r\n"
            . chunk_split(base64_encode($attachment->content));
    }

    private function address(string $email, string $name): string
    {
        $email = str_replace(["\r", "\n", '<', '>'], '', $email);
        if ($name === '') {
            return $email;
        }
        $encoded = $this->encodeHeader($name);
        // «García, Ana» sin comillas se leería como dos direcciones.
        if ($encoded === $name && preg_match('/[()<>@,;:\\\\".\[\]]/', $name)) {
            $encoded = '"' . addcslashes($name, '"\\') . '"';
        }

        return sprintf('%s <%s>', $encoded, $email);
    }

    private function wrapHtml(Message $message): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"></head>'
            . '<body style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1F201D">'
            . $message->html
            . '</body></html>';
    }
}
