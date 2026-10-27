<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use DateTimeImmutable;

final class LoginAttemptRepository extends BaseRepository
{
    public function countSince(string $key, DateTimeImmutable $since): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM login_intento WHERE clave = :key AND creado_en >= :since',
            ['key' => $key, 'since' => DbTime::toDb($since)]
        );
    }

    public function add(string $key, DateTimeImmutable $at): void
    {
        $this->insert('login_intento', ['clave' => $key, 'creado_en' => DbTime::toDb($at)]);
    }

    public function clear(string $key): void
    {
        $this->execute('DELETE FROM login_intento WHERE clave = :key', ['key' => $key]);
    }

    public function purgeBefore(DateTimeImmutable $before): void
    {
        $this->execute('DELETE FROM login_intento WHERE creado_en < :before', ['before' => DbTime::toDb($before)]);
    }
}
