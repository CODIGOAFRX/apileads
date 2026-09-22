<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use DateTimeImmutable;
use Leads\Domain\Lead\BusinessCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessCalendarTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'martes → miércoles 9:00' => ['2026-09-15T17:30:00+02:00', '2026-09-16T09:00:00+02:00'],
            'viernes → lunes 9:00' => ['2026-09-18T12:00:00+02:00', '2026-09-21T09:00:00+02:00'],
            'sábado → lunes 9:00' => ['2026-09-19T11:00:00+02:00', '2026-09-21T09:00:00+02:00'],
            'antes de las 9 también pasa al día siguiente' => ['2026-09-15T08:00:00+02:00', '2026-09-16T09:00:00+02:00'],
            '23:30 en Madrid es otro día en UTC' => ['2026-09-15T23:30:00+02:00', '2026-09-16T09:00:00+02:00'],
            'cambio de hora de octubre' => ['2026-10-23T18:00:00+02:00', '2026-10-26T09:00:00+01:00'],
        ];
    }

    #[DataProvider('cases')]
    public function testCalculaLaSiguienteMananaLaborable(string $now, string $expected): void
    {
        $calendar = new BusinessCalendar('Europe/Madrid', '09:00');

        $next = $calendar->nextBusinessMorning(new DateTimeImmutable($now));

        self::assertSame('UTC', $next->getTimezone()->getName());
        self::assertEquals(new DateTimeImmutable($expected), $next);
    }

    public function testSaltaFestivos(): void
    {
        $calendar = new BusinessCalendar('Europe/Madrid', '09:00', ['2026-10-12']);

        $next = $calendar->nextBusinessMorning(new DateTimeImmutable('2026-10-09T10:00:00+02:00'));

        self::assertEquals(new DateTimeImmutable('2026-10-13T09:00:00+02:00'), $next);
    }

    public function testRechazaHoraMalFormada(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BusinessCalendar('Europe/Madrid', '9h');
    }
}
