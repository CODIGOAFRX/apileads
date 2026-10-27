<?php

declare(strict_types=1);

namespace Leads\Core;

use DateTimeImmutable;

/**
 * Fuente de tiempo inyectable: el dominio nunca llama a time() ni a NOW() de SQL,
 * así las reglas de fechas (vuelta a las 9:00, bloqueos) se pueden probar.
 */
interface Clock
{
    /** Instante actual en UTC. */
    public function now(): DateTimeImmutable;
}
