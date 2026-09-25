<?php

declare(strict_types=1);

namespace Leads\Application;

use DateTimeZone;
use Leads\Core\Clock;
use Leads\Domain\Lead\LeadOrigin;
use Leads\Domain\Lead\LeadSearch;
use Leads\Domain\Lead\LeadStatus;
use Leads\Infrastructure\Persistence\DbTime;
use Leads\Infrastructure\Persistence\LeadRepository;
use Leads\Infrastructure\Spreadsheet\CsvWriter;

/** Exporta a CSV el resultado del buscador (p. ej. descartados para campañas de correo). */
final class ExportService
{
    private const HEADERS = ['ID', 'Nombre', 'Apellidos', 'Email', 'Teléfono', 'Ubicación', 'Estudios', 'Interés (texto)', 'Origen', 'Estado', 'Intentos', 'Motivo descarte', 'Entrada', 'Última llamada', 'Comentarios'];

    public function __construct(
        private readonly LeadRepository $leads,
        private readonly Clock $clock,
        private readonly string $timezone = 'Europe/Madrid',
    ) {
    }

    public function csv(array $query): string
    {
        $search = LeadSearch::fromQuery($query);
        $zone = new DateTimeZone($this->timezone);
        $local = static fn (?string $v): string => $v ? DbTime::fromDb($v)->setTimezone($zone)->format('d/m/Y H:i') : '';

        $rows = (function () use ($search, $local) {
            foreach ($this->leads->iterate($search, $this->clock->now(), $this->timezone) as $row) {
                yield [
                    $row['id'],
                    $row['nombre'],
                    $row['apellidos'],
                    $row['email'],
                    $row['telefono'],
                    $row['ubicacion'],
                    implode(', ', array_column($row['estudios'], 'nombre')),
                    $row['interes_texto'],
                    (LeadOrigin::tryFrom((string) $row['origen']) ?? LeadOrigin::OTRO)->label(),
                    LeadStatus::from($row['estado'])->label(),
                    $row['intentos_sin_respuesta'],
                    $row['motivo_descarte'],
                    $local($row['ultima_entrada']),
                    $local($row['ultima_llamada']),
                    $row['comentarios'],
                ];
            }
        })();

        return (new CsvWriter())->build(self::HEADERS, $rows);
    }
}
