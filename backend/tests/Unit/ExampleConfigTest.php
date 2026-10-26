<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Core\ExampleConfig;
use PHPUnit\Framework\TestCase;

final class ExampleConfigTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['APP_DEBUG', 'JWT_SECRET', 'ADMIN_PASSWORD', 'DB_PASSWORD', 'MAIL_PASSWORD'] as $key) {
            $this->saved[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testDetectaLosValoresDeEjemploEnProduccion(): void
    {
        $_ENV['JWT_SECRET'] = 'pon-aqui-un-secreto-largo-y-aleatorio';
        $_ENV['ADMIN_PASSWORD'] = 'cambia-esta-contraseña-de-admin';
        $_ENV['DB_PASSWORD'] = 'una-de-verdad-2026';
        $_ENV['MAIL_PASSWORD'] = 'contraseña-del-buzon';

        self::assertSame(['JWT_SECRET', 'ADMIN_PASSWORD', 'MAIL_PASSWORD'], ExampleConfig::leftovers());
    }

    public function testDetectaElSecretoDelDockerLocalAunqueVengaEnBase64(): void
    {
        $_ENV['JWT_SECRET'] = 'bG9jYWwtc29sby1wYXJhLWRlc2Fycm9sbG8tbm8tdXNhci1lbi1wcm9kdWNjaW9u';

        $this->expectException(\RuntimeException::class);
        ExampleConfig::assertJwtSecretIsReal();
    }

    public function testUnSecretoRealPasa(): void
    {
        $_ENV['JWT_SECRET'] = base64_encode(random_bytes(48));
        $_ENV['ADMIN_PASSWORD'] = 'Xq9-larga-y-propia';

        self::assertSame([], ExampleConfig::leftovers());
        ExampleConfig::assertJwtSecretIsReal();
    }

    public function testEnLocalNoSeComprueba(): void
    {
        $_ENV['APP_DEBUG'] = '1';
        $_ENV['JWT_SECRET'] = 'pon-aqui-un-secreto-largo-y-aleatorio';

        self::assertSame([], ExampleConfig::leftovers());
    }
}
