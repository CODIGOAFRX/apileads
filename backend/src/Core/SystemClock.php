<?php

declare(strict_types=1);

namespace Leads\Core;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
