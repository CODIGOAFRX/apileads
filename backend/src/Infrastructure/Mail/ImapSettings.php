<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

final class ImapSettings
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly string $username,
        public readonly string $password,
        // null = se busca sola (carpeta marcada como \Sent o con un nombre habitual).
        public readonly ?string $sentFolder = null,
        public readonly int $timeout = 20,
        public readonly bool $verifyPeer = true,
    ) {
        if (!in_array($encryption, SmtpTransport::ENCRYPTIONS, true)) {
            throw new \InvalidArgumentException('El cifrado IMAP debe ser ssl, tls o none');
        }
    }
}
