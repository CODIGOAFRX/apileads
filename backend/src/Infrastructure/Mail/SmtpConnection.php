<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Mail;

/** Canal de comunicación con un servidor SMTP. */
interface SmtpConnection
{
    public function write(string $line): void;

    public function readLine(): ?string;

    public function enableTls(): void;

    public function close(): void;
}
