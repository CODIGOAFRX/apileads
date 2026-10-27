<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

/** Resultado de aplicar una acción comercial: nuevo estado y entrada para el historial. */
final class Transition
{
    public function __construct(
        public readonly LeadState $state,
        public readonly string $activityType,
        public readonly string $summary,
        public readonly array $detail = [],
    ) {
    }
}
