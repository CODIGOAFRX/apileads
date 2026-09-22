<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

use Leads\Domain\Mail\Message;

/** Construye el mensaje MIME (texto + HTML) en UTF-8. */
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
        $boundary = 'b' . bin2hex(random_bytes(12));
        $headers = [
            'Date' => date(DATE_RFC2822),
            'From' => $this->address($this->fromEmail, $this->fromName),
            'To' => $this->address($message->to, $message->toName),
            'Subject' => $this->encodeHeader($message->subject),
            'Message-ID' => sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain),
            'MIME-Version' => '1.0',
            'Content-Type' => "multipart/alternative; boundary=\"{$boundary}\"",
        ];
        if ($message->replyTo !== null && filter_var($message->replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers['Reply-To'] = $message->replyTo;
        }

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($message->text()))
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($this->wrapHtml($message)))
            . "--{$boundary}--\r\n";

        return ['headers' => $headers, 'body' => $body];
    }

    public function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    private function address(string $email, string $name): string
    {
        $email = str_replace(["\r", "\n", '<', '>'], '', $email);
        return $name === '' ? $email : sprintf('%s <%s>', $this->encodeHeader($name), $email);
    }

    private function wrapHtml(Message $message): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"></head>'
            . '<body style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1F201D">'
            . $message->html
            . '</body></html>';
    }
}
