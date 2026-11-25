<?php

declare(strict_types=1);

namespace Leads\Domain\Import;

use DateTimeImmutable;
use DateTimeZone;

/** Interpreta las fechas que traen las exportaciones (ISO, dd/mm/aaaa, número de serie de Excel…). */
final class DateParser
{
    private const FORMATS = [
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y', 'd.m.Y H:i', 'd.m.Y',
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'Y/m/d H:i:s', 'Y/m/d', 'd/m/y H:i', 'd/m/y',
    ];

    /** Con am/pm la exportación es americana (Meta, aunque esté en español): primero mes/día. */
    private const FORMATS_12H = ['m/d/Y g:ia', 'm/d/Y g:i:sa', 'd/m/Y g:ia', 'd/m/Y g:i:sa', 'Y-m-d g:ia', 'm/d/y g:ia'];

    private readonly DateTimeZone $zone;

    public function __construct(string $timezone = 'Europe/Madrid')
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public function parse(?string $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Número de serie de Excel (días desde 1899-12-30).
        if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
            $serial = (float) $value;
            if ($serial > 30000 && $serial < 80000) {
                $seconds = (int) round(($serial - 25569) * 86400);
                $utc = (new DateTimeImmutable('@' . $seconds));
                return new DateTimeImmutable($utc->format('Y-m-d H:i:s'), $this->zone);
            }
            return null;
        }

        // ISO 8601 con zona (Facebook: 2026-09-15T10:23:11+0200).
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
            try {
                return new DateTimeImmutable($value, $this->zone);
            } catch (\Exception) {
                return null;
            }
        }

        $clean = (string) preg_replace('/\s+/', ' ', str_replace([',', ' h'], ['', ''], $value));
        $formats = self::FORMATS;
        if (preg_match('/\d\s*([ap])\.?\s*m\.?$/i', $clean, $m)) {
            $clean = (string) preg_replace('/\s*[ap]\.?\s*m\.?$/i', strtolower($m[1]) . 'm', $clean);
            $formats = self::FORMATS_12H;
        }
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $clean, $this->zone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $year = (int) $date->format('Y');
                return $year >= 2000 && $year <= 2100 ? $date : null;
            }
        }

        return null;
    }
}
