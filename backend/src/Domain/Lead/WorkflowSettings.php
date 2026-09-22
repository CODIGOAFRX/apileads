<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

/** Parámetros del flujo comercial (configurables desde .env). */
final class WorkflowSettings
{
    public function __construct(
        /** Al llegar a este número de intentos sin respuesta el lead se descarta solo (0 = nunca). */
        public readonly int $maxAttempts = 6,
        /** Minutos que un lead queda reservado para la comercial que lo está llamando. */
        public readonly int $lockMinutes = 5,
    ) {
        if ($maxAttempts < 0 || $lockMinutes < 1) {
            throw new \InvalidArgumentException('Configuración del flujo comercial no válida');
        }
    }
}
