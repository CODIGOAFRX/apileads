<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use DateTimeImmutable;

/**
 * Pila de deshacer de cada usuario. Los deshechos son siempre los últimos de la
 * pila (un cambio nuevo borra los que quedaban por rehacer), así que se deshace
 * el último sin deshacer y se rehace el primero deshecho.
 */
final class UndoRepository extends BaseRepository
{
    /** Cuántos cambios recuerda cada usuario. */
    private const KEEP = 50;

    public function record(int $userId, int $leadId, string $description, array $before, array $after, DateTimeImmutable $now): void
    {
        // Un cambio nuevo hace imposible rehacer lo deshecho.
        $this->execute('DELETE FROM deshacer WHERE usuario_id = :user AND deshecho_en IS NOT NULL', ['user' => $userId]);
        $this->insert('deshacer', [
            'usuario_id' => $userId,
            'lead_id' => $leadId,
            'descripcion' => mb_substr($description, 0, 255),
            'antes' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'despues' => json_encode($after, JSON_UNESCAPED_UNICODE),
            'creado_en' => DbTime::toDb($now),
        ]);
        $oldest = $this->scalar(
            'SELECT id FROM deshacer WHERE usuario_id = :user ORDER BY id DESC LIMIT 1 OFFSET ' . self::KEEP,
            ['user' => $userId]
        );
        if ($oldest !== null) {
            $this->execute('DELETE FROM deshacer WHERE usuario_id = :user AND id <= :id', ['user' => $userId, 'id' => (int) $oldest]);
        }
    }

    /** Último cambio que se puede deshacer (hecho después de $since). */
    public function lastUndoable(int $userId, DateTimeImmutable $since): ?array
    {
        return $this->decode($this->one(
            'SELECT d.*, l.nombre, l.apellidos, l.telefono FROM deshacer d JOIN leads l ON l.id = d.lead_id
             WHERE d.usuario_id = :user AND d.deshecho_en IS NULL AND d.creado_en >= :since
             ORDER BY d.id DESC LIMIT 1',
            ['user' => $userId, 'since' => DbTime::toDb($since)]
        ));
    }

    /** Primer cambio deshecho, que es el que se rehace. */
    public function firstRedoable(int $userId, DateTimeImmutable $since): ?array
    {
        return $this->decode($this->one(
            'SELECT d.*, l.nombre, l.apellidos, l.telefono FROM deshacer d JOIN leads l ON l.id = d.lead_id
             WHERE d.usuario_id = :user AND d.deshecho_en IS NOT NULL AND d.creado_en >= :since
             ORDER BY d.id ASC LIMIT 1',
            ['user' => $userId, 'since' => DbTime::toDb($since)]
        ));
    }

    public function markUndone(int $id, ?DateTimeImmutable $at): void
    {
        $this->update('deshacer', $id, ['deshecho_en' => DbTime::toDb($at)]);
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM deshacer WHERE id = :id', ['id' => $id]);
    }

    private function decode(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['antes'] = json_decode((string) $row['antes'], true) ?: [];
        $row['despues'] = json_decode((string) $row['despues'], true) ?: [];

        return $row;
    }
}
