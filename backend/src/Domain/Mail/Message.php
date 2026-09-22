<?php

declare(strict_types=1);

namespace Leads\Domain\Mail;

final class Message
{
    public function __construct(
        public readonly string $to,
        public readonly string $toName,
        public readonly string $subject,
        public readonly string $html,
        public readonly ?string $replyTo = null,
    ) {
    }

    /** Versión en texto plano para clientes que no muestran HTML. */
    public function text(): string
    {
        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|h[1-6]|li|tr)>#i'], ["\n", "\n\n"], $this->html) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
