<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

use DateTimeImmutable;

/** Parte del lead que gobierna el flujo comercial. Inmutable. */
final class LeadState
{
    public function __construct(
        public readonly LeadStatus $status = LeadStatus::NUEVO,
        public readonly int $attempts = 0,
        public readonly ?DateTimeImmutable $nextCall = null,
        public readonly ?DateTimeImmutable $lastCall = null,
        public readonly ?DateTimeImmutable $appointmentAt = null,
        public readonly ?string $discardReason = null,
        /** Día y hora acordados con el lead para volver a llamarle. */
        public readonly ?DateTimeImmutable $scheduledCall = null,
    ) {
    }

    public function with(array $changes): self
    {
        $values = get_object_vars($this);
        foreach ($changes as $key => $value) {
            if (!array_key_exists($key, $values)) {
                throw new \LogicException("Campo de estado desconocido: {$key}");
            }
            $values[$key] = $value;
        }

        return new self(...$values);
    }
}
