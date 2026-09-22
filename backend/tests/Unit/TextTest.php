<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Domain\Shared\Email;
use Leads\Domain\Shared\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testNormalizaTildesMayusculasYEspacios(): void
    {
        self::assertSame('andres nunez garcia', Text::normalize("  ANDRÉS   Núñez\u{00A0}García "));
        self::assertSame('que estudios te interesan', Text::slug('¿Qué_estudios_te_interesan?'));
    }

    public function testEscapaComodinesDeLike(): void
    {
        self::assertSame('%50!% de!_descuento!!%', Text::likeContains('50% de_descuento!'));
    }

    public function testCorrigeNombresEnMayusculasPeroRespetaLosMixtos(): void
    {
        self::assertSame('María José', Text::properName('MARÍA JOSÉ'));
        self::assertSame('Ana De La Torre', Text::properName('ana de la torre'));
        self::assertSame('McDonald', Text::properName('McDonald'));
    }

    public function testLimpiaCorreos(): void
    {
        self::assertSame('lucia@example.com', Email::clean(' mailto:Lucia@Example.com. '));
        self::assertTrue(Email::isValid('lucia@example.com'));
        self::assertFalse(Email::isValid('lucia@'));
        self::assertNull(Email::clean('  '));
    }
}
