<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;

/** Conversión de fechas entre PHP, la base de datos (UTC) y la API (ISO 8601). */
final class DbTime
{
    public const FORMAT = 'Y-m-d H:i:s';

    public static function toDb(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function fromDb(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public static function toApi(?string $value): ?string
    {
        return $value === null || $value === '' ? null : self::fromDb($value)->format('Y-m-d\TH:i:s\Z');
    }

    /** Inicio de un día local (Y-m-d) expresado en UTC para la base de datos. */
    public static function localDayStart(string $date, string $timezone, int $addDays = 0): string
    {
        $day = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone($timezone));
        if ($addDays !== 0) {
            $day = $day->modify(sprintf('%+d day', $addDays));
        }

        return self::toDb($day);
    }
}
