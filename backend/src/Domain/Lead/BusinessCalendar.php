<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Calcula la próxima mañana laborable (lunes a viernes, sin festivos) a la
 * hora de reapertura, en la zona horaria de la escuela. Devuelve UTC.
 */
final class BusinessCalendar
{
    private readonly DateTimeZone $zone;
    /** @var array<string, true> */
    private readonly array $holidays;

    /** @param list<string> $holidays fechas Y-m-d */
    public function __construct(
        string $timezone = 'Europe/Madrid',
        private readonly string $openingTime = '09:00',
        array $holidays = [],
    ) {
        $this->zone = new DateTimeZone($timezone);
        $this->holidays = array_fill_keys($holidays, true);
        if (!preg_match('/^\d{2}:\d{2}$/', $openingTime)) {
            throw new \InvalidArgumentException('La hora de apertura debe tener formato HH:MM');
        }
    }

    public function nextBusinessMorning(DateTimeImmutable $now): DateTimeImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->openingTime));
        $day = $now->setTimezone($this->zone)->setTime(0, 0)->modify('+1 day');
        while (!$this->isBusinessDay($day)) {
            $day = $day->modify('+1 day');
        }

        return $day->setTime($hour, $minute)->setTimezone(new DateTimeZone('UTC'));
    }

    /** La misma fecha en hora de la escuela, para mostrarla. */
    public function local(DateTimeImmutable $at): DateTimeImmutable
    {
        return $at->setTimezone($this->zone);
    }

    public function isBusinessDay(DateTimeImmutable $day): bool
    {
        $local = $day->setTimezone($this->zone);
        return (int) $local->format('N') <= 5 && !isset($this->holidays[$local->format('Y-m-d')]);
    }
}
