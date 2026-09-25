<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Core\Input;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testTextoDeCualquierEscalar(): void
    {
        $data = ['n' => 612345678, 'd' => 1.5, 't' => true, 'f' => false, 's' => ' hola ', 'null' => null];

        self::assertSame('612345678', Input::text($data, 'n'));
        self::assertSame('1.5', Input::text($data, 'd'));
        self::assertSame('1', Input::text($data, 't'));
        self::assertSame('', Input::text($data, 'f'));
        self::assertSame(' hola ', Input::text($data, 's'), 'no recorta: eso lo decide quien lo usa');
        self::assertSame('defecto', Input::text($data, 'null', 'defecto'));
        self::assertSame('defecto', Input::text($data, 'no-existe', 'defecto'));
        self::assertNull(Input::optionalText($data, 'no-existe'));
    }

    public function testLimpiaBytesNulosYUtf8Invalido(): void
    {
        self::assertSame('2026-09-15', Input::text(['f' => "2026-09\0-15"], 'f'));
        self::assertSame('ok?', Input::text(['x' => "ok\xFF"], 'x'));
        self::assertSame('Ñandú 😀', Input::text(['x' => 'Ñandú 😀'], 'x'));
    }

    public function testUnaListaDondeVaUnTextoEsUnErrorDelQueLlama(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('El dato «nota» no es válido');
        Input::text(['nota' => ['a']], 'nota');
    }

    public function testIds(): void
    {
        self::assertSame([3, 5], Input::ids('3, 5,3'));
        self::assertSame([3, 5], Input::ids([3, '5', 0, -2, 'x', 1.5, ['7'], null, true]));
        self::assertSame([7], Input::ids(7));
        self::assertSame([], Input::ids(null));

        self::assertSame(12, Input::id(' 12 '));
        self::assertNull(Input::id('12abc'));
        self::assertNull(Input::id(['12']));
        self::assertNull(Input::id(0));
        self::assertNull(Input::id('-3'));
    }
}
