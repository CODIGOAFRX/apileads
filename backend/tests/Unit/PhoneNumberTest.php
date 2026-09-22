<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Domain\Shared\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase
{
    public static function spanishFormats(): array
    {
        return [
            'con espacios' => ['600 12 34 54'],
            'con prefijo +' => ['+34 600123454'],
            'con 0034' => ['0034-600-123-454'],
            'facebook' => ['p:+34600123454'],
            'con paréntesis y puntos' => ['(+34) 600.12.34.54'],
            'excel científico' => ['3.4600123454E+10'],
            'dos números' => ['600123454 / 958000111'],
        ];
    }

    #[DataProvider('spanishFormats')]
    public function testNormalizaFormatosEspanoles(string $raw): void
    {
        $phone = PhoneNumber::tryParse($raw);

        self::assertNotNull($phone);
        self::assertSame('34600123454', $phone->digits());
        self::assertSame('+34 600 12 34 54', $phone->formatted());
    }

    public function testConservaNumerosExtranjeros(): void
    {
        $phone = PhoneNumber::tryParse('+44 7911 123456');

        self::assertSame('447911123456', $phone->digits());
        self::assertFalse($phone->isSpanish());
        self::assertSame('+447911123456', $phone->formatted());
    }

    public function testDescartaValoresQueNoSonTelefonos(): void
    {
        self::assertNull(PhoneNumber::tryParse(''));
        self::assertNull(PhoneNumber::tryParse('no tengo'));
        self::assertNull(PhoneNumber::tryParse('12345'));
        self::assertNull(PhoneNumber::tryParse(null));
    }

    public function testGeneraEnlaceDeWhatsapp(): void
    {
        $url = PhoneNumber::tryParse('600123454')->whatsappUrl('Hola Lucía');

        self::assertSame('https://wa.me/34600123454?text=Hola%20Luc%C3%ADa', $url);
    }
}
