<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

final class SmtpSettings
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly string $username,
        public readonly string $password,
        public readonly string $fromEmail,
        public readonly int $timeout = 20,
    ) {
        if (!in_array($encryption, SmtpTransport::ENCRYPTIONS, true)) {
            throw new \InvalidArgumentException('MAIL_ENCRYPTION debe ser ssl, tls o none');
        }
    }
}
