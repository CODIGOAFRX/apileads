<?php

declare(strict_types=1);

namespace Leads\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Reloj fijo para pruebas y scripts. */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string|DateTimeImmutable $now)
    {
        $this->set($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string|DateTimeImmutable $now): void
    {
        $value = is_string($now) ? new DateTimeImmutable($now) : $now;
        $this->now = $value->setTimezone(new DateTimeZone('UTC'));
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
