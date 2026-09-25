<?php

declare(strict_types=1);

namespace Leads\Domain\Mail;

/** Fichero adjunto a un correo (p. ej. el dossier de un estudio). */
final class Attachment
{
    public function __construct(
        public readonly string $name,
        public readonly string $mimeType,
        public readonly string $content,
    ) {
    }
}
