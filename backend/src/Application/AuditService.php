<?php

declare(strict_types=1);

namespace Leads\Application;

use DateTimeImmutable;
use DateTimeZone;
use Leads\Core\Clock;
use Leads\Domain\Lead\ActivityType;
use Leads\Infrastructure\Persistence\AuditRepository;
use Leads\Infrastructure\Persistence\DbTime;
use Leads\Infrastructure\Persistence\UserRepository;
use Leads\Infrastructure\Spreadsheet\CsvWriter;

/**
 * Registro de actividad para administración: qué ha hecho cada comercial cada
 * día. Junta el historial de los leads (llamadas, correos, WhatsApp, notas,
 * cambios de estado…) con el registro general (entrar, abrir una llamada o una
 * ficha, pulsar el teléfono, importar, exportar, ajustes).
 */
final class AuditService
{
    // Tipos del registro general (los de los leads están en ActivityType).
    public const ENTRADA = 'ENTRADA';
    public const LLAMADA_ABIERTA = 'LLAMADA_ABIERTA';
    public const MARCADO = 'MARCADO';
    public const FICHA = 'FICHA';
    public const IMPORTACION = 'IMPORTACION';
    public const EXPORTACION = 'EXPORTACION';
    public const AJUSTES = 'AJUSTES';

    public const LABELS = [
        self::ENTRADA => 'Entra en la aplicación',
        self::LLAMADA_ABIERTA => 'Abre la llamada',
        self::MARCADO => 'Pulsa el teléfono',
        self::FICHA => 'Abre la ficha',
        self::IMPORTACION => 'Importación',
        self::EXPORTACION => 'Exportación',
        self::AJUSTES => 'Ajustes',
        ActivityType::LLAMADA => 'Llamada',
        ActivityType::WHATSAPP => 'WhatsApp',
        ActivityType::CORREO => 'Correo',
        ActivityType::NOTA => 'Nota',
        ActivityType::CAMBIO_ESTADO => 'Cambio de estado',
        ActivityType::CREADO => 'Alta de lead',
        ActivityType::IMPORTADO => 'Lead importado',
        ActivityType::FUSIONADO => 'Lead repetido',
        ActivityType::EDITADO => 'Datos editados',
    ];

    private const CSV_HEADERS = ['Fecha', 'Hora', 'Usuario', 'Acción', 'Lead', 'Detalle'];

    public function __construct(
        private readonly AuditRepository $audit,
        private readonly UserRepository $users,
        private readonly Clock $clock,
        private readonly string $timezone = 'Europe/Madrid',
    ) {
    }

    /**
     * Anota una acción. Con $dedupeSeconds no se repite la misma acción del mismo
     * usuario sobre el mismo lead dentro de ese margen (doble clic, recargas…).
     */
    public function record(int $userId, string $type, string $summary, array $detail = [], ?int $leadId = null, ?string $ip = null, int $dedupeSeconds = 0): void
    {
        $now = $this->clock->now();
        if ($dedupeSeconds > 0 && $this->audit->loggedSince($userId, $type, $leadId, $now->modify("-{$dedupeSeconds} seconds"))) {
            return;
        }
        $this->audit->log($userId, $type, $summary, $detail, $leadId, $ip === 'unknown' ? null : $ip, $now);
    }

    /** Resumen por usuario y lista de acciones de un día (hora de Madrid). $date: Y-m-d; null = hoy. */
    public function day(?string $date, ?int $userId = null): array
    {
        [$date, $from, $to] = $this->range($date);
        $events = array_map(fn (array $row): array => $this->event($row), $this->audit->between($from, $to, $userId));

        return [
            'fecha' => $date,
            'usuarios' => array_map(static fn (array $u): array => ['id' => (int) $u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol'], 'activo' => (bool) $u['activo']], $this->users->listAll()),
            'tipos' => self::LABELS,
            'resumen' => $this->summary($events),
            'eventos' => $events,
        ];
    }

    public function csv(?string $date, ?int $userId = null): string
    {
        $day = $this->day($date, $userId);
        $zone = new DateTimeZone($this->timezone);
        $rows = array_map(static function (array $e) use ($zone): array {
            $at = (new DateTimeImmutable($e['fecha']))->setTimezone($zone);
            return [$at->format('d/m/Y'), $at->format('H:i:s'), $e['usuario'] ?? '—', $e['accion'], $e['lead']['nombre'] ?? '', $e['resumen']];
        }, array_reverse($day['eventos']));

        return (new CsvWriter())->build(self::CSV_HEADERS, $rows);
    }

    /** @return array{0: string, 1: DateTimeImmutable, 2: DateTimeImmutable} */
    private function range(?string $date): array
    {
        $zone = new DateTimeZone($this->timezone);
        if ($date === null || $date === '') {
            $date = $this->clock->now()->setTimezone($zone)->format('Y-m-d');
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Fecha no válida');
        }

        return [$date, $day, $day->modify('+1 day')];
    }

    private function event(array $row): array
    {
        $leadName = trim(($row['lead_nombre'] ?? '') . ' ' . ($row['lead_apellidos'] ?? ''));

        return [
            'clave' => $row['origen'] . '-' . $row['id'],
            'fecha' => DbTime::toApi($row['creado_en']),
            'usuarioId' => $row['usuario_id'] === null ? null : (int) $row['usuario_id'],
            'usuario' => $row['usuario'],
            'tipo' => $row['tipo'],
            'accion' => self::LABELS[$row['tipo']] ?? $row['tipo'],
            'resumen' => $row['resumen'],
            'detalle' => $row['detalle'] ? json_decode($row['detalle'], true) : null,
            'lead' => $row['lead_id'] === null ? null : ['id' => (int) $row['lead_id'], 'nombre' => $leadName !== '' ? $leadName : 'Lead #' . $row['lead_id']],
        ];
    }

    /** Cifras del día por usuario, de más a menos activo. */
    private function summary(array $events): array
    {
        $byUser = [];
        foreach (array_reverse($events) as $e) {
            if ($e['usuarioId'] === null) {
                continue;
            }
            $s = $byUser[$e['usuarioId']] ?? [
                'usuarioId' => $e['usuarioId'], 'usuario' => $e['usuario'], 'primera' => $e['fecha'], 'ultima' => $e['fecha'],
                'acciones' => 0, 'leads' => [], 'llamadasAbiertas' => 0, 'marcados' => 0, 'llamadas' => 0,
                'contestan' => 0, 'noContestan' => 0, 'interesados' => 0, 'agendadas' => 0, 'noInteresados' => 0,
                'whatsapp' => 0, 'correos' => 0, 'correosError' => 0, 'notas' => 0,
                'citas' => 0, 'matriculas' => 0, 'descartes' => 0, 'altas' => 0,
            ];
            $s['ultima'] = $e['fecha'];
            $s['acciones']++;
            if ($e['lead'] !== null && $e['tipo'] !== self::FICHA) {
                $s['leads'][$e['lead']['id']] = true;
            }
            $outcome = $e['detalle']['resultado'] ?? null;
            match ($e['tipo']) {
                self::LLAMADA_ABIERTA => $s['llamadasAbiertas']++,
                self::MARCADO => $s['marcados']++,
                ActivityType::WHATSAPP => $s['whatsapp']++,
                ActivityType::NOTA => $s['notas']++,
                ActivityType::CREADO => $s['altas']++,
                ActivityType::CORREO => match (true) {
                    str_starts_with($e['resumen'], 'Información enviada') => $s['correos']++,
                    str_starts_with($e['resumen'], 'Error') => $s['correosError']++,
                    default => null,
                },
                default => null,
            };
            if ($e['tipo'] === ActivityType::LLAMADA) {
                $s['llamadas']++;
                $outcome === 'NO_CONTESTA' ? $s['noContestan']++ : $s['contestan']++;
                match ($outcome) {
                    'INTERESADO' => $s['interesados']++,
                    'NO_INTERESADO' => $s['noInteresados']++,
                    'AGENDADA' => $s['agendadas']++,
                    default => null,
                };
            }
            if ($e['tipo'] === ActivityType::CAMBIO_ESTADO) {
                match (true) {
                    str_starts_with($e['resumen'], 'Marcado como interesado') => $s['interesados']++,
                    str_starts_with($e['resumen'], 'Cita agendada') => $s['citas']++,
                    str_starts_with($e['resumen'], 'Matriculado') => $s['matriculas']++,
                    str_starts_with($e['resumen'], 'Descartado') => $s['descartes']++,
                    default => null,
                };
            }
            $byUser[$e['usuarioId']] = $s;
        }

        $summary = array_map(static fn (array $s): array => ['leads' => count($s['leads'])] + $s, array_values($byUser));
        usort($summary, static fn (array $a, array $b): int => $b['acciones'] <=> $a['acciones']);

        return $summary;
    }
}
