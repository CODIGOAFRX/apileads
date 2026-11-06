<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

/**
 * Qué botones se ofrecen para un lead. El frontend solo pinta esta lista,
 * así la regla vive en un único sitio y está probada.
 */
final class LeadActionPolicy
{
    public const LLAMAR = 'llamar';
    public const WHATSAPP = 'whatsapp';
    public const ENVIAR_INFO = 'enviar-info';
    public const INTERESADO = 'interesado';
    public const CITAR = 'citar';
    public const MATRICULAR = 'matricular';
    public const DESCARTAR = 'descartar';
    public const REABRIR = 'reabrir';

    /** @return list<string> */
    public function availableActions(LeadStatus $status, bool $hasPhone, bool $hasValidEmail): array
    {
        if (!$status->isOpen()) {
            return [self::REABRIR];
        }

        // WhatsApp y correo están disponibles desde el primer momento, conteste o no.
        $actions = [];
        if ($hasPhone) {
            $actions[] = self::LLAMAR;
            $actions[] = self::WHATSAPP;
        }
        if ($hasValidEmail) {
            $actions[] = self::ENVIAR_INFO;
        }
        if ($status->isInterested()) {
            $actions[] = self::CITAR;
            $actions[] = self::MATRICULAR;
        } else {
            // Marcar interés sin llamar (p. ej. porque ha contestado por WhatsApp).
            $actions[] = self::INTERESADO;
        }
        $actions[] = self::DESCARTAR;

        return $actions;
    }
}
