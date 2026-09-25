<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

/** Buzón de correo de cada usuario (uno como mucho). */
final class MailAccountRepository extends BaseRepository
{
    public function findByUser(int $userId): ?array
    {
        return $this->one('SELECT * FROM cuenta_correo WHERE usuario_id = :id', ['id' => $userId]);
    }

    public function save(int $userId, array $values): void
    {
        $existing = $this->findByUser($userId);
        if ($existing !== null) {
            $this->update('cuenta_correo', (int) $existing['id'], $values);
            return;
        }
        $this->insert('cuenta_correo', $values + ['usuario_id' => $userId]);
    }

    public function delete(int $userId): void
    {
        $this->execute('DELETE FROM cuenta_correo WHERE usuario_id = :id', ['id' => $userId]);
    }
}
