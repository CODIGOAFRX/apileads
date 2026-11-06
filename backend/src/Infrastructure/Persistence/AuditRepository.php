<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use DateTimeImmutable;

/**
 * Registro de actividad: la tabla «registro» (lo que no va al historial de un
 * lead) y, para consultar, esa tabla junto con lead_actividad.
 */
final class AuditRepository extends BaseRepository
{
    public function log(?int $userId, string $type, string $summary, array $detail, ?int $leadId, ?string $ip, DateTimeImmutable $at): void
    {
        $this->insert('registro', [
            'usuario_id' => $userId,
            'lead_id' => $leadId,
            'tipo' => $type,
            'resumen' => mb_substr($summary, 0, 255),
            'detalle' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
            'ip' => $ip,
            'creado_en' => DbTime::toDb($at),
        ]);
    }

    /** ¿Ya se anotó lo mismo hace poco? (doble clic, abrir y cerrar enseguida…) */
    public function loggedSince(int $userId, string $type, ?int $leadId, DateTimeImmutable $since): bool
    {
        return (bool) $this->scalar(
            'SELECT COUNT(*) FROM registro WHERE usuario_id = :usuario AND tipo = :tipo AND creado_en >= :since
             AND ' . ($leadId === null ? 'lead_id IS NULL' : 'lead_id = :lead'),
            ['usuario' => $userId, 'tipo' => $type, 'since' => DbTime::toDb($since)] + ($leadId === null ? [] : ['lead' => $leadId])
        );
    }

    /**
     * Todo lo que pasó en [$from, $to), lo más reciente primero.
     *
     * @return list<array{origen: string, id: int, usuario_id: ?int, usuario: ?string, tipo: string, resumen: string,
     *                    detalle: ?string, lead_id: ?int, lead_nombre: ?string, lead_apellidos: ?string, creado_en: string}>
     */
    public function between(DateTimeImmutable $from, DateTimeImmutable $to, ?int $userId = null): array
    {
        $params = ['from1' => DbTime::toDb($from), 'to1' => DbTime::toDb($to), 'from2' => DbTime::toDb($from), 'to2' => DbTime::toDb($to)];
        $byUser1 = $byUser2 = '';
        if ($userId !== null) {
            $byUser1 = ' AND a.usuario_id = :u1';
            $byUser2 = ' AND r.usuario_id = :u2';
            $params += ['u1' => $userId, 'u2' => $userId];
        }

        return $this->all(
            "SELECT * FROM (
             SELECT 'lead' AS origen, a.id, a.usuario_id, u.nombre AS usuario, a.tipo, a.resumen, a.detalle,
                    a.lead_id, l.nombre AS lead_nombre, l.apellidos AS lead_apellidos, a.creado_en
             FROM lead_actividad a
             LEFT JOIN usuario u ON u.id = a.usuario_id
             LEFT JOIN leads l ON l.id = a.lead_id
             WHERE a.creado_en >= :from1 AND a.creado_en < :to1{$byUser1}
             UNION ALL
             SELECT 'registro' AS origen, r.id, r.usuario_id, u.nombre AS usuario, r.tipo, r.resumen, r.detalle,
                    r.lead_id, l.nombre AS lead_nombre, l.apellidos AS lead_apellidos, r.creado_en
             FROM registro r
             LEFT JOIN usuario u ON u.id = r.usuario_id
             LEFT JOIN leads l ON l.id = r.lead_id
             WHERE r.creado_en >= :from2 AND r.creado_en < :to2{$byUser2}
             ) t ORDER BY t.creado_en DESC, t.id DESC",
            $params
        );
    }
}
