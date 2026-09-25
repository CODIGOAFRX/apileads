<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

final class UserRepository extends BaseRepository
{
    public function findByEmail(string $email): ?array
    {
        return $this->one('SELECT * FROM usuario WHERE email = :email', ['email' => mb_strtolower(trim($email))]);
    }

    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM usuario WHERE id = :id', ['id' => $id]);
    }

    public function listAll(): array
    {
        return $this->all(
            'SELECT u.id, u.nombre, u.email, u.rol, u.activo, u.ultimo_acceso, u.creado_en, c.email AS correo_envio
             FROM usuario u LEFT JOIN cuenta_correo c ON c.usuario_id = u.id ORDER BY u.nombre'
        );
    }

    public function create(array $values): int
    {
        return $this->insert('usuario', $values);
    }

    public function save(int $id, array $values): void
    {
        $this->update('usuario', $id, $values);
    }

    public function countActiveAdmins(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM usuario WHERE rol = 'ADMIN' AND activo = 1");
    }

    public function count(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM usuario');
    }
}
