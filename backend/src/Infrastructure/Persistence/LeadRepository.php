<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use DateTimeImmutable;
use Leads\Domain\Lead\Company;
use Leads\Domain\Lead\Inbox;
use Leads\Domain\Lead\LeadSearch;
use Leads\Domain\Shared\Text;

final class LeadRepository extends BaseRepository
{
    /**
     * Mismo criterio que {@see Inbox::of()}: una llamada agendada manda sobre
     * el estado mientras el lead siga abierto.
     */
    private const INBOX_SQL = [
        'pendientes' => "((l.llamada_agendada IS NULL AND (l.estado IN ('NUEVO', 'AGENDADO')
                              OR (l.estado = 'NO_CONTESTA' AND (l.proxima_llamada IS NULL OR l.proxima_llamada <= :now))))
                          OR (l.llamada_agendada <= :now AND l.estado NOT IN ('MATRICULADO', 'DESCARTADO', 'HISTORICO')))",
        'agendadas' => "(l.llamada_agendada > :now AND l.estado NOT IN ('MATRICULADO', 'DESCARTADO', 'HISTORICO'))",
        'no-contestan' => "(l.llamada_agendada IS NULL AND l.estado = 'NO_CONTESTA' AND l.proxima_llamada > :now)",
        'interesados' => "(l.llamada_agendada IS NULL AND l.estado IN ('INTERESADO', 'CITA'))",
        'matriculados' => "l.estado = 'MATRICULADO'",
        'descartados' => "l.estado = 'DESCARTADO'",
        // Todos menos los descartados (los que están en gestión y los contactos históricos).
        'historico' => "l.estado <> 'DESCARTADO'",
    ];

    private const SORT_SQL = [
        'entrada' => 'l.ultima_entrada',
        'nombre' => 'l.nombre_busqueda',
        'proxima' => 'COALESCE(l.llamada_agendada, l.proxima_llamada)',
        'ultima-llamada' => 'l.ultima_llamada',
    ];

    /** Orden fijo por delante del elegido: las llamadas agendadas, por su hora. */
    private const PINNED_ORDER = [
        'pendientes' => 'CASE WHEN l.llamada_agendada IS NULL THEN 1 ELSE 0 END, l.llamada_agendada ASC, ',
        'agendadas' => 'l.llamada_agendada ASC, ',
    ];

    private const SELECT = 'SELECT l.*, u.nombre AS bloqueado_por_nombre, c.nombre AS centro_nombre
        FROM leads l
        LEFT JOIN usuario u ON u.id = l.bloqueado_por
        LEFT JOIN centro c ON c.id = l.centro_id';

    public function create(array $values): int
    {
        return $this->insert('leads', $values);
    }

    public function save(int $id, array $values): void
    {
        $this->update('leads', $id, $values);
    }

    public function find(int $id): ?array
    {
        $row = $this->one(self::SELECT . ' WHERE l.id = :id', ['id' => $id]);
        if ($row === null) {
            return null;
        }

        return $this->withStudies([$row])[0];
    }

    /** Busca un lead existente con el mismo correo o teléfono. */
    public function findDuplicate(?string $email, ?string $phoneDigits, ?int $excludeId = null): ?array
    {
        $conditions = [];
        $params = [];
        if ($email !== null && $email !== '') {
            $conditions[] = 'email_normalizado = :email';
            $params['email'] = $email;
        }
        if ($phoneDigits !== null && $phoneDigits !== '') {
            $conditions[] = 'telefono_digitos = :phone';
            $params['phone'] = $phoneDigits;
        }
        if ($conditions === []) {
            return null;
        }
        $sql = 'SELECT id FROM leads WHERE (' . implode(' OR ', $conditions) . ')';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }
        $id = $this->scalar($sql . ' ORDER BY id LIMIT 1', $params);

        return $id === null ? null : $this->find((int) $id);
    }

    /** @return list<int> */
    public function studyIds(int $leadId): array
    {
        return array_map('intval', array_column(
            $this->all('SELECT estudio_id FROM lead_estudio WHERE lead_id = :id', ['id' => $leadId]),
            'estudio_id'
        ));
    }

    /** @param list<int> $studyIds */
    public function replaceStudies(int $leadId, array $studyIds): void
    {
        $this->execute('DELETE FROM lead_estudio WHERE lead_id = :id', ['id' => $leadId]);
        $this->addStudies($leadId, $studyIds);
    }

    /** @param list<int> $studyIds */
    public function addStudies(int $leadId, array $studyIds): void
    {
        $existing = $this->studyIds($leadId);
        foreach (array_unique($studyIds) as $studyId) {
            if (!in_array((int) $studyId, $existing, true)) {
                $this->insert('lead_estudio', ['lead_id' => $leadId, 'estudio_id' => (int) $studyId]);
            }
        }
    }

    /**
     * Reserva el lead para una comercial durante unos minutos.
     * Devuelve false si otra persona lo tiene reservado.
     */
    public function lock(int $leadId, int $userId, DateTimeImmutable $now, DateTimeImmutable $until): bool
    {
        $this->execute(
            'UPDATE leads SET bloqueado_por = :user, bloqueado_hasta = :until
             WHERE id = :id AND (bloqueado_por IS NULL OR bloqueado_por = :user2 OR bloqueado_hasta IS NULL OR bloqueado_hasta <= :now)',
            [
                'user' => $userId,
                'user2' => $userId,
                'until' => DbTime::toDb($until),
                'id' => $leadId,
                'now' => DbTime::toDb($now),
            ]
        );

        // No vale contar las filas del UPDATE: MariaDB cuenta 0 si los valores no
        // cambian (la misma persona reservando dos veces en el mismo segundo).
        $owner = $this->scalar('SELECT bloqueado_por FROM leads WHERE id = :id', ['id' => $leadId]);

        return $owner !== null && (int) $owner === $userId;
    }

    public function unlock(int $leadId, int $userId): void
    {
        $this->execute(
            'UPDATE leads SET bloqueado_por = NULL, bloqueado_hasta = NULL WHERE id = :id AND bloqueado_por = :user',
            ['id' => $leadId, 'user' => $userId]
        );
    }

    /** @return array{items: list<array>, total: int} */
    public function search(LeadSearch $search, DateTimeImmutable $now, string $timezone): array
    {
        [$where, $params] = $this->buildWhere($search, $now, $timezone);
        $total = (int) $this->scalar("SELECT COUNT(*) FROM leads l WHERE {$where}", $params);

        $column = self::SORT_SQL[$search->sort];
        $direction = $search->direction === 'asc' ? 'ASC' : 'DESC';
        // Los NULL siempre al final, en ambos motores.
        $order = (self::PINNED_ORDER[$search->inbox->value] ?? '')
            . "CASE WHEN {$column} IS NULL THEN 1 ELSE 0 END, {$column} {$direction}, l.id {$direction}";
        $offset = ($search->page - 1) * $search->perPage;

        $rows = $this->all(
            self::SELECT . " WHERE {$where} ORDER BY {$order} LIMIT {$search->perPage} OFFSET {$offset}",
            $params
        );

        return ['items' => $this->withStudies($rows), 'total' => $total];
    }

    /** Cursor sin paginar para exportaciones. */
    public function iterate(LeadSearch $search, DateTimeImmutable $now, string $timezone): \Generator
    {
        [$where, $params] = $this->buildWhere($search, $now, $timezone);
        $lastId = 0;
        do {
            $rows = $this->all(
                self::SELECT . " WHERE {$where} AND l.id > :__last ORDER BY l.id LIMIT 500",
                $params + ['__last' => $lastId]
            );
            foreach ($this->withStudies($rows) as $row) {
                $lastId = (int) $row['id'];
                yield $row;
            }
        } while (count($rows) === 500);
    }

    /** @return array<int, string> id => nombre del estudio sin tildes ni mayúsculas (como el buscador) */
    private function normalizedStudyNames(): array
    {
        $names = [];
        foreach ($this->all('SELECT id, nombre FROM estudio') as $row) {
            $names[(int) $row['id']] = Text::normalize($row['nombre']);
        }

        return $names;
    }

    /** Llamadas agendadas entre esas horas en leads abiertos, por orden de hora. */
    public function scheduledCalls(DateTimeImmutable $from, DateTimeImmutable $to, ?string $company = null): array
    {
        return $this->all(
            "SELECT l.id, l.nombre, l.apellidos, l.telefono, l.llamada_agendada, l.agendada_por FROM leads l
             WHERE l.llamada_agendada >= :desde AND l.llamada_agendada <= :hasta
               AND l.estado NOT IN ('MATRICULADO', 'DESCARTADO', 'HISTORICO')"
            . ($company === null ? '' : ' AND ' . self::companyCondition($company))
            . ' ORDER BY l.llamada_agendada',
            ['desde' => DbTime::toDb($from), 'hasta' => DbTime::toDb($to)]
        );
    }

    /** De Estación Diseño si alguno de sus estudios lo es; si no, de Grupo EFP (ver Company). */
    private static function companyCondition(string $company): string
    {
        return ($company === Company::ESTACION->value ? '' : 'NOT ')
            . "EXISTS (SELECT 1 FROM lead_estudio le4 JOIN estudio e4 ON e4.id = le4.estudio_id
               WHERE le4.lead_id = l.id AND e4.empresa = '" . Company::ESTACION->value . "')";
    }

    /**
     * @param ?string $company solo los leads de esa empresa (usuario limitado a una)
     * @return array<string,int> número de leads por pestaña
     */
    public function countByInbox(DateTimeImmutable $now, ?string $company = null): array
    {
        $cases = [];
        $params = [];
        foreach (array_keys(self::INBOX_SQL) as $inbox) {
            $alias = str_replace('-', '_', $inbox);
            [$condition, $conditionParams] = $this->inboxCondition($inbox, $now, "now_{$alias}_");
            $cases[] = "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) AS {$alias}";
            $params += $conditionParams;
        }
        $where = $company === null ? '' : ' WHERE ' . self::companyCondition($company);
        $row = $this->one('SELECT COUNT(*) AS todos, ' . implode(', ', $cases) . ' FROM leads l' . $where, $params) ?? [];

        $counts = [];
        foreach (Inbox::cases() as $inbox) {
            $counts[$inbox->value] = (int) ($row[str_replace('-', '_', $inbox->value)] ?? 0);
        }

        return $counts;
    }

    /** @return array{0:string, 1:array} */
    private function buildWhere(LeadSearch $search, DateTimeImmutable $now, string $timezone): array
    {
        $where = ['1 = 1'];
        $params = [];

        if ($search->inbox !== Inbox::TODOS) {
            [$condition, $params] = $this->inboxCondition($search->inbox->value, $now, 'now_');
            $where[] = $condition;
        }

        $digits = $search->textDigits();
        if ($digits !== null) {
            $where[] = "(l.telefono_digitos LIKE :q_phone ESCAPE '!' OR l.texto_busqueda LIKE :q_text ESCAPE '!')";
            $params['q_phone'] = Text::likeContains($digits);
            $params['q_text'] = Text::likeContains(Text::normalize($search->text));
        } else {
            // Cada palabra, en sus datos o en el nombre de alguno de sus estudios («maya hansen», «procreate»).
            $studies = null;
            foreach ($search->words() as $i => $word) {
                $studies ??= $this->normalizedStudyNames();
                $ids = array_keys(array_filter($studies, static fn (string $name): bool => str_contains($name, $word)));
                $byStudy = '';
                if ($ids !== []) {
                    [$in, $inParams] = $this->inClause("qs{$i}_", $ids);
                    $byStudy = " OR EXISTS (SELECT 1 FROM lead_estudio lq{$i} WHERE lq{$i}.lead_id = l.id AND lq{$i}.estudio_id IN ({$in}))";
                    $params += $inParams;
                }
                $where[] = "(l.texto_busqueda LIKE :q{$i} ESCAPE '!'{$byStudy})";
                $params["q{$i}"] = Text::likeContains($word);
            }
        }

        if ($search->name !== '') {
            foreach (explode(' ', Text::normalize($search->name)) as $i => $word) {
                $where[] = "l.nombre_busqueda LIKE :name{$i} ESCAPE '!'";
                $params["name{$i}"] = Text::likeContains($word);
            }
        }
        if ($search->email !== '') {
            $where[] = "l.email_normalizado LIKE :email ESCAPE '!'";
            $params['email'] = Text::likeContains(mb_strtolower($search->email));
        }
        if ($search->phoneDigits !== '') {
            $where[] = "l.telefono_digitos LIKE :phone ESCAPE '!'";
            $params['phone'] = Text::likeContains($search->phoneDigits);
        }
        if ($search->location !== '') {
            $where[] = "LOWER(l.ubicacion) LIKE :location ESCAPE '!'";
            $params['location'] = Text::likeContains(mb_strtolower($search->location));
        }
        if ($search->studyIds !== []) {
            [$in, $inParams] = $this->inClause('study', $search->studyIds);
            $where[] = "EXISTS (SELECT 1 FROM lead_estudio le WHERE le.lead_id = l.id AND le.estudio_id IN ({$in}))";
            $params += $inParams;
        }
        if ($search->studyType !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM lead_estudio le2 JOIN estudio e2 ON e2.id = le2.estudio_id
                        WHERE le2.lead_id = l.id AND e2.tipo = :study_type)';
            $params['study_type'] = $search->studyType;
        }
        if ($search->company !== null) {
            $where[] = self::companyCondition($search->company);
        }
        if ($search->withoutStudy) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM lead_estudio le3 WHERE le3.lead_id = l.id)';
        }
        if ($search->origin !== null) {
            $where[] = 'l.origen = :origin';
            $params['origin'] = $search->origin;
        }
        if ($search->centerId !== null) {
            $where[] = 'l.centro_id = :center';
            $params['center'] = $search->centerId;
        }
        if ($search->status !== null) {
            $where[] = 'l.estado = :status';
            $params['status'] = $search->status;
        }
        if ($search->from !== null) {
            $where[] = 'l.ultima_entrada >= :from';
            $params['from'] = DbTime::localDayStart($search->from, $timezone);
        }
        if ($search->to !== null) {
            $where[] = 'l.ultima_entrada < :to';
            $params['to'] = DbTime::localDayStart($search->to, $timezone, 1);
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Condición de una pestaña con un parámetro distinto por cada :now, porque
     * MariaDB (sin emulación) no admite repetir un mismo nombre.
     *
     * @return array{0:string, 1:array<string,string>}
     */
    private function inboxCondition(string $inbox, DateTimeImmutable $now, string $prefix): array
    {
        $params = [];
        $sql = (string) preg_replace_callback('/:now\b/', function () use (&$params, $prefix, $now): string {
            $name = $prefix . count($params);
            $params[$name] = DbTime::toDb($now);
            return ':' . $name;
        }, self::INBOX_SQL[$inbox]);

        return [$sql, $params];
    }

    /** Añade a cada fila la lista de estudios de interés (una sola consulta). */
    private function withStudies(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        [$in, $params] = $this->inClause('lead', array_column($rows, 'id'));
        $studies = $this->all(
            "SELECT le.lead_id, e.id, e.nombre, e.tipo, e.color, e.empresa
             FROM lead_estudio le JOIN estudio e ON e.id = le.estudio_id
             WHERE le.lead_id IN ({$in}) ORDER BY e.orden, e.nombre",
            $params
        );
        $byLead = [];
        foreach ($studies as $study) {
            $byLead[(int) $study['lead_id']][] = [
                'id' => (int) $study['id'],
                'nombre' => $study['nombre'],
                'tipo' => $study['tipo'],
                'color' => $study['color'],
                'empresa' => $study['empresa'],
            ];
        }
        foreach ($rows as &$row) {
            $row['estudios'] = $byLead[(int) $row['id']] ?? [];
        }

        return $rows;
    }
}
