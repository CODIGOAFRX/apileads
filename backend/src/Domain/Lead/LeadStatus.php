<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

/**
 * Embudo comercial: NUEVO → (NO_CONTESTA | AGENDADO) → INTERESADO → CITA → MATRICULADO.
 * DESCARTADO puede alcanzarse desde cualquier punto y nunca se borra.
 * HISTORICO: contactos antiguos importados como histórico (p. ej. el antiguo SAC);
 * no se llaman salvo que se reabran o vuelvan a pedir información.
 */
enum LeadStatus: string
{
    case NUEVO = 'NUEVO';
    case NO_CONTESTA = 'NO_CONTESTA';
    case AGENDADO = 'AGENDADO';
    case INTERESADO = 'INTERESADO';
    case CITA = 'CITA';
    case MATRICULADO = 'MATRICULADO';
    case DESCARTADO = 'DESCARTADO';
    case HISTORICO = 'HISTORICO';

    public function label(): string
    {
        return match ($this) {
            self::NUEVO => 'Nuevo',
            self::NO_CONTESTA => 'No contesta',
            self::AGENDADO => 'Llamada agendada',
            self::INTERESADO => 'Interesado',
            self::CITA => 'Cita agendada',
            self::MATRICULADO => 'Matriculado',
            self::DESCARTADO => 'Descartado',
            self::HISTORICO => 'Histórico',
        };
    }

    /** Estados en los que el lead sigue en gestión y se le puede llamar. */
    public function isOpen(): bool
    {
        return !in_array($this, [self::MATRICULADO, self::DESCARTADO, self::HISTORICO], true);
    }

    public function isInterested(): bool
    {
        return in_array($this, [self::INTERESADO, self::CITA], true);
    }
}
