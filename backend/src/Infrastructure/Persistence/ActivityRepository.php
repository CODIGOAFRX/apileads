<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use DateTimeImmutable;

final class ActivityRepository extends BaseRepository
{
    public function log(int $leadId, ?int $userId, string $type, string $summary, array $detail, DateTimeImmutable $at): void
    {
        $this->insert('lead_actividad', [
            'lead_id' => $leadId,
            'usuario_id' => $userId,
            'tipo' => $type,
            'resumen' => mb_substr($summary, 0, 255),
            'detalle' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
            'creado_en' => DbTime::toDb($at),
        ]);
    }

    public function forLead(int $leadId): array
    {
        $rows = $this->all(
            'SELECT a.id, a.tipo, a.resumen, a.detalle, a.creado_en, u.nombre AS usuario
             FROM lead_actividad a LEFT JOIN usuario u ON u.id = a.usuario_id
             WHERE a.lead_id = :id ORDER BY a.creado_en DESC, a.id DESC',
            ['id' => $leadId]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'tipo' => $r['tipo'],
            'resumen' => $r['resumen'],
            'detalle' => $r['detalle'] ? json_decode($r['detalle'], true) : null,
            'usuario' => $r['usuario'],
            'fecha' => DbTime::toApi($r['creado_en']),
        ], $rows);
    }

    /** Llamadas por comercial en un rango (para el resumen diario). */
    public function callsByUser(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->all(
            "SELECT u.nombre AS usuario, COUNT(*) AS llamadas
             FROM lead_actividad a JOIN usuario u ON u.id = a.usuario_id
             WHERE a.tipo = 'LLAMADA' AND a.creado_en >= :from AND a.creado_en < :to
             GROUP BY u.nombre ORDER BY llamadas DESC",
            ['from' => DbTime::toDb($from), 'to' => DbTime::toDb($to)]
        );
    }
}
