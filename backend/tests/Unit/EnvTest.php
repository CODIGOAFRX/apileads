<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Core\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testLeeElFicheroSinPisarVariablesYaDefinidas(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "# comentario\nPRUEBA_A=\"con espacios y = igual\"\nPRUEBA_B=7\nPRUEBA_C=\nPRUEBA_D=a, b ,c\nPRUEBA_YA=fichero\n");
        $_ENV['PRUEBA_YA'] = 'entorno';

        Env::load($file);
        unlink($file);

        self::assertSame('con espacios y = igual', Env::get('PRUEBA_A'));
        self::assertSame(7, Env::int('PRUEBA_B', 0));
        self::assertSame('defecto', Env::get('PRUEBA_C', 'defecto'));
        self::assertSame(['a', 'b', 'c'], Env::list('PRUEBA_D'));
        self::assertSame('entorno', Env::get('PRUEBA_YA'));
        self::assertFalse(Env::bool('PRUEBA_NO_EXISTE'));
    }
}
