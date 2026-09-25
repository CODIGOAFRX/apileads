<?php

declare(strict_types=1);

namespace Leads\Application;

use Leads\Core\Clock;
use Leads\Core\HttpException;
use Leads\Domain\User\Role;
use Leads\Infrastructure\Persistence\DbTime;
use Leads\Infrastructure\Persistence\LoginAttemptRepository;
use Leads\Infrastructure\Persistence\UserRepository;
use Leads\Infrastructure\Security\JwtService;

final class AuthService
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW = '-15 minutes';
    private const MIN_PASSWORD_LENGTH = 10;
    private const DUMMY_HASH = '$2y$12$6hZYeRhrxC2gRRRsDcQC9uwa.GPCATNoE7p9dxeStslERr5R8jHWq';

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $attempts,
        private readonly JwtService $jwt,
        private readonly Clock $clock,
    ) {
    }

    public function login(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $key = hash('sha256', $email . '|' . $ip);
        $now = $this->clock->now();

        if ($this->attempts->countSince($key, $now->modify(self::WINDOW)) >= self::MAX_ATTEMPTS) {
            throw HttpException::tooManyRequests('Demasiados intentos. Espera unos minutos y vuelve a probar.');
        }

        $user = $email === '' ? null : $this->users->findByEmail($email);
        // Se verifica siempre un hash para no revelar por tiempos si el usuario existe.
        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        $valid = password_verify($password, $hash);

        if ($user === null || !$valid || !(bool) $user['activo']) {
            $this->attempts->add($key, $now);
            throw HttpException::unauthorized('Correo o contraseña incorrectos');
        }

        $this->attempts->clear($key);
        $this->attempts->purgeBefore($now->modify('-1 day'));
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->save((int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        $this->users->save((int) $user['id'], ['ultimo_acceso' => DbTime::toDb($now)]);

        return [
            'token' => $this->jwt->issue((int) $user['id'], $user['rol']),
            'expiraEn' => $this->jwt->ttlSeconds(),
            'usuario' => self::present($user),
        ];
    }

    /** Resuelve el usuario de un token; null si no es válido o la cuenta está desactivada. */
    public function userFromToken(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $payload = $this->jwt->verify($token);
        if ($payload === null) {
            return null;
        }
        $user = $this->users->find((int) $payload['sub']);
        if ($user === null || !(bool) $user['activo']) {
            return null;
        }

        return self::present($user);
    }

    public function listUsers(): array
    {
        return array_map([self::class, 'present'], $this->users->listAll());
    }

    public function createUser(array $data): array
    {
        $name = trim((string) ($data['nombre'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $role = Role::tryFrom((string) ($data['rol'] ?? '')) ?? Role::COMERCIAL;
        $password = (string) ($data['password'] ?? '');

        $this->assertValidProfile($name, $email);
        $this->assertValidPassword($password);
        if ($this->users->findByEmail($email) !== null) {
            throw HttpException::conflict('Ya existe un usuario con ese correo');
        }

        $id = $this->users->create([
            'nombre' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'rol' => $role->value,
            'activo' => 1,
            'creado_en' => DbTime::toDb($this->clock->now()),
        ]);

        return self::present($this->users->find($id));
    }

    public function updateUser(int $id, array $data, int $actingUserId): array
    {
        $user = $this->users->find($id) ?? throw HttpException::notFound('Usuario no encontrado');
        $name = trim((string) ($data['nombre'] ?? $user['nombre']));
        $email = mb_strtolower(trim((string) ($data['email'] ?? $user['email'])));
        $role = Role::tryFrom((string) ($data['rol'] ?? $user['rol'])) ?? Role::COMERCIAL;
        $active = array_key_exists('activo', $data) ? (bool) $data['activo'] : (bool) $user['activo'];

        $this->assertValidProfile($name, $email);
        $other = $this->users->findByEmail($email);
        if ($other !== null && (int) $other['id'] !== $id) {
            throw HttpException::conflict('Ya existe un usuario con ese correo');
        }
        $losesAdmin = $user['rol'] === Role::ADMIN->value && ($role !== Role::ADMIN || !$active);
        if ($losesAdmin && $this->users->countActiveAdmins() <= 1) {
            throw HttpException::conflict('Debe quedar al menos un administrador activo');
        }
        if ($id === $actingUserId && !$active) {
            throw HttpException::conflict('No puedes desactivar tu propia cuenta');
        }

        $values = ['nombre' => $name, 'email' => $email, 'rol' => $role->value, 'activo' => $active ? 1 : 0];
        if (($data['password'] ?? '') !== '') {
            $this->assertValidPassword((string) $data['password']);
            $values['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }
        $this->users->save($id, $values);

        return self::present($this->users->find($id));
    }

    public function changeOwnPassword(int $userId, string $current, string $new): void
    {
        $user = $this->users->find($userId) ?? throw HttpException::notFound('Usuario no encontrado');
        if (!password_verify($current, $user['password_hash'])) {
            throw HttpException::badRequest('La contraseña actual no es correcta');
        }
        $this->assertValidPassword($new);
        $this->users->save($userId, ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
    }

    /** Crea el primer administrador si la tabla está vacía (desde .env). */
    public function ensureBootstrapAdmin(?string $email, ?string $password): void
    {
        if ($email === null || $password === null || $this->users->count() > 0) {
            return;
        }
        $this->createUser(['nombre' => 'Administración', 'email' => $email, 'password' => $password, 'rol' => Role::ADMIN->value]);
    }

    public static function present(array $user): array
    {
        $presented = [
            'id' => (int) $user['id'],
            'nombre' => $user['nombre'],
            'email' => $user['email'],
            'rol' => $user['rol'],
            'activo' => (bool) $user['activo'],
            'ultimoAcceso' => DbTime::toApi($user['ultimo_acceso'] ?? null),
        ];
        // Solo en el listado de usuarios: buzón desde el que envía (null = el general).
        if (array_key_exists('correo_envio', $user)) {
            $presented['correoEnvio'] = $user['correo_envio'];
        }

        return $presented;
    }

    private function assertValidProfile(string $name, string $email): void
    {
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Indica un nombre (máximo 120 caracteres)');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Indica un correo válido');
        }
    }

    private function assertValidPassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos ' . self::MIN_PASSWORD_LENGTH . ' caracteres');
        }
    }
}
