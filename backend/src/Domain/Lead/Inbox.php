<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

/**
 * Pestañas del listado. "Pendientes" es la principal: leads nuevos, los que
 * no contestaron cuya próxima llamada ya ha llegado y las llamadas agendadas
 * cuya hora ha llegado. Se calcula al consultar, así que no depende de
 * ninguna tarea programada.
 */
enum Inbox: string
{
    case PENDIENTES = 'pendientes';
    case AGENDADAS = 'agendadas';
    case NO_CONTESTAN = 'no-contestan';
    case INTERESADOS = 'interesados';
    case MATRICULADOS = 'matriculados';
    case DESCARTADOS = 'descartados';
    case TODOS = 'todos';

    /** Bandeja en la que aparece un lead en un instante dado. */
    public static function of(
        LeadStatus $status,
        ?\DateTimeImmutable $nextCall,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $scheduledCall = null,
    ): self {
        // Una llamada agendada manda sobre el estado mientras el lead siga abierto.
        if ($scheduledCall !== null && $status->isOpen()) {
            return $scheduledCall <= $now ? self::PENDIENTES : self::AGENDADAS;
        }

        return match ($status) {
            LeadStatus::NUEVO, LeadStatus::AGENDADO => self::PENDIENTES,
            LeadStatus::NO_CONTESTA => ($nextCall === null || $nextCall <= $now) ? self::PENDIENTES : self::NO_CONTESTAN,
            LeadStatus::INTERESADO, LeadStatus::CITA => self::INTERESADOS,
            LeadStatus::MATRICULADO => self::MATRICULADOS,
            LeadStatus::DESCARTADO => self::DESCARTADOS,
        };
    }
}
