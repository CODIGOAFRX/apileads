<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use DateTimeImmutable;

/**
 * Plantillas de correo y registro de envíos. Cada plantilla es de un estudio,
 * la genérica (sin estudio ni clave) o una especial identificada por su clave.
 */
final class MailRepository extends BaseRepository
{
    /** Para quien no ha dicho qué quiere estudiar. */
    public const NO_STUDY = 'sin-estudio';
    public const KEYS = [self::NO_STUDY];

    public function templates(): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'estudioId' => $r['estudio_id'] === null ? null : (int) $r['estudio_id'],
            'clave' => $r['clave'],
            'estudio' => $r['estudio'],
            'asunto' => $r['asunto'],
            'cuerpoHtml' => $r['cuerpo_html'],
            'actualizado' => DbTime::toApi($r['actualizado_en']),
        ], $this->all(
            'SELECT p.*, e.nombre AS estudio FROM plantilla_correo p LEFT JOIN estudio e ON e.id = p.estudio_id
             ORDER BY CASE WHEN p.estudio_id IS NOT NULL THEN 2 WHEN p.clave IS NULL THEN 0 ELSE 1 END, e.orden, e.nombre'
        ));
    }

    /** Plantilla de un estudio o, si $studyId es null, la genérica. */
    public function templateFor(?int $studyId): ?array
    {
        return $studyId === null
            ? $this->one('SELECT * FROM plantilla_correo WHERE estudio_id IS NULL AND clave IS NULL')
            : $this->one('SELECT * FROM plantilla_correo WHERE estudio_id = :id', ['id' => $studyId]);
    }

    public function templateByKey(string $key): ?array
    {
        return $this->one('SELECT * FROM plantilla_correo WHERE estudio_id IS NULL AND clave = :clave', ['clave' => $key]);
    }

    public function saveTemplate(?int $studyId, ?string $key, string $subject, string $body, DateTimeImmutable $now): void
    {
        $values = ['asunto' => $subject, 'cuerpo_html' => $body, 'actualizado_en' => DbTime::toDb($now)];
        $existing = $key !== null ? $this->templateByKey($key) : $this->templateFor($studyId);
        if ($existing !== null) {
            $this->update('plantilla_correo', (int) $existing['id'], $values);
            return;
        }
        $this->insert('plantilla_correo', $values + ['estudio_id' => $key !== null ? null : $studyId, 'clave' => $key]);
    }

    public function deleteTemplate(int $studyId): void
    {
        $this->execute('DELETE FROM plantilla_correo WHERE estudio_id = :id', ['id' => $studyId]);
    }

    public function logDelivery(int $leadId, ?int $userId, string $to, string $subject, ?string $error, DateTimeImmutable $at): void
    {
        $this->insert('envio_correo', [
            'lead_id' => $leadId,
            'usuario_id' => $userId,
            'destinatario' => $to,
            'asunto' => mb_substr($subject, 0, 190),
            'estado' => $error === null ? 'ENVIADO' : 'ERROR',
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            'creado_en' => DbTime::toDb($at),
        ]);
    }

    public function countSentTo(int $leadId, DateTimeImmutable $since): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM envio_correo WHERE lead_id = :id AND estado = 'ENVIADO' AND creado_en >= :since",
            ['id' => $leadId, 'since' => DbTime::toDb($since)]
        );
    }

    /** Fecha (API) del último correo enviado bien a este lead, o null. */
    public function lastSentAt(int $leadId): ?string
    {
        $value = $this->scalar(
            "SELECT MAX(creado_en) FROM envio_correo WHERE lead_id = :id AND estado = 'ENVIADO'",
            ['id' => $leadId]
        );

        return DbTime::toApi($value === null ? null : (string) $value);
    }
}
