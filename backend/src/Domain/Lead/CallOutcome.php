<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

/** Resultado que registra la comercial al pulsar el botón de llamada. */
enum CallOutcome: string
{
    case NO_CONTESTA = 'NO_CONTESTA';
    case NO_INTERESADO = 'NO_INTERESADO';
    case INTERESADO = 'INTERESADO';

    public function label(): string
    {
        return match ($this) {
            self::NO_CONTESTA => 'No contesta',
            self::NO_INTERESADO => 'Contesta · no interesado',
            self::INTERESADO => 'Contesta · interesado',
        };
    }
}
