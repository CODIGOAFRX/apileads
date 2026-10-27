<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Leads\Core\Clock;

final class JwtService
{
    private readonly string $key;

    public function __construct(string $secret, private readonly Clock $clock, private readonly int $ttlSeconds = 43200)
    {
        $decoded = base64_decode($secret, true);
        $this->key = $decoded !== false && strlen($decoded) >= 32 ? $decoded : $secret;
        if (strlen($this->key) < 32) {
            throw new \RuntimeException('JWT_SECRET debe tener al menos 32 bytes', 500);
        }
    }

    public function issue(int $userId, string $role): string
    {
        $now = $this->clock->now()->getTimestamp();
        return JWT::encode([
            'sub' => (string) $userId,
            'rol' => $role,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
        ], $this->key, 'HS256');
    }

    /** @return array{sub:string, rol:string}|null */
    public function verify(string $token): ?array
    {
        try {
            JWT::$timestamp = $this->clock->now()->getTimestamp();
            $payload = (array) JWT::decode($token, new Key($this->key, 'HS256'));
        } catch (\Throwable) {
            return null;
        } finally {
            JWT::$timestamp = null;
        }

        return isset($payload['sub'], $payload['rol']) ? $payload : null;
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }
}
