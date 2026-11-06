<?php

declare(strict_types=1);

namespace Leads\Domain\Mail;

/** Envío de correo desacoplado: SMTP en producción, fichero de log en local. */
interface MailTransport
{
    /** @throws MailException si el envío falla */
    public function send(Message $message): void;
}
