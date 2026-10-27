<?php

declare(strict_types=1);

namespace Leads\Application;

use Leads\Core\Clock;
use Leads\Core\Input;
use Leads\Domain\Import\ColumnMapper;
use Leads\Domain\Import\ColumnMapping;
use Leads\Domain\Import\DateParser;
use Leads\Domain\Import\ImportField;
use Leads\Domain\Import\RowMapper;
use Leads\Domain\Import\StudyMatcher;
use Leads\Domain\Import\Table;
use Leads\Domain\Lead\ActivityType;
use Leads\Domain\Lead\LeadOrigin;
use Leads\Infrastructure\Persistence\CatalogRepository;
use Leads\Infrastructure\Persistence\DbTime;
use Leads\Infrastructure\Persistence\ImportRepository;
use Leads\Infrastructure\Persistence\LeadRepository;
use Leads\Infrastructure\Spreadsheet\ImportTemplate;
use PDO;

/**
 * Importación en dos pasos, sin estado en el servidor:
 *  1. previsualizar: analiza el fichero y devuelve columnas, muestra y estudios sin reconocer;
 *  2. importar: se reenvía el fichero con los ajustes elegidos y se da de alta.
 * Una fila con problemas nunca detiene la importación: se anota y se sigue.
 */
final class ImportService
{
    private const CHUNK = 100;
    private const MAX_ISSUES = 200;

    public function __construct(
        private readonly PDO $pdo,
        private readonly LeadService $leadService,
        private readonly LeadRepository $leads,
        private readonly CatalogRepository $catalog,
        private readonly ImportRepository $imports,
        private readonly ColumnMapper $mapper,
        private readonly Clock $clock,
        private readonly string $timezone = 'Europe/Madrid',
    ) {
    }

    public function preview(Table $table, array $options): array
    {
        $mapping = $this->mapping($table, $options);
        $origin = $this->defaultOrigin($mapping, $options);
        $rowMapper = $this->rowMapper($mapping, $origin, $options);

        $pending = [];
        $withoutContact = 0;
        $duplicatesInDb = 0;
        $duplicatesInFile = 0;
        $seen = [];
        $sample = [];
        $warnings = 0;

        foreach ($mapping->dataRows as $index => $row) {
            $mapped = $rowMapper->map($row);
            $input = $mapped['input'];
            if (!$input->hasContactData()) {
                $withoutContact++;
                continue;
            }
            foreach ($mapped['pendientes'] as $item) {
                $pending[$item['clave']] ??= ['clave' => $item['clave'], 'texto' => $item['texto'], 'filas' => 0];
                $pending[$item['clave']]['filas']++;
            }
            $warnings += count($mapped['avisos']);

            $keys = array_filter([$input->email ? 'e:' . $input->email : null, $input->phone ? 't:' . $input->phone->digits() : null]);
            if (array_intersect($keys, array_keys($seen)) !== []) {
                $duplicatesInFile++;
            } elseif ($this->leads->findDuplicate($input->email, $input->phone?->digits()) !== null) {
                $duplicatesInDb++;
            }
            foreach ($keys as $key) {
                $seen[$key] = true;
            }

            if (count($sample) < 8) {
                $sample[] = [
                    'fila' => $index + 1,
                    'nombre' => trim($input->firstName . ' ' . $input->lastName),
                    'email' => $input->email,
                    'telefono' => $input->displayPhone(),
                    'ubicacion' => $input->location,
                    'interes' => $input->interestText,
                    'estudioIds' => $input->studyIds,
                    'origen' => $input->origin->value,
                    'fecha' => $input->sourceDate?->format('Y-m-d H:i'),
                    'avisos' => $mapped['avisos'],
                ];
            }
        }

        $total = count($mapping->dataRows);
        usort($pending, static fn ($a, $b) => $b['filas'] <=> $a['filas']);

        return [
            'columnas' => $mapping->describe(),
            'campos' => array_map(
                static fn (ImportField $f): array => ['valor' => $f->value, 'etiqueta' => $f->label()],
                ImportField::cases()
            ),
            'filaCabecera' => $mapping->headerRow === null ? null : $mapping->headerRow + 1,
            'origenDetectado' => $origin->value,
            'totalFilas' => $total,
            'importables' => $total - $withoutContact,
            'sinContacto' => $withoutContact,
            'yaExisten' => $duplicatesInDb,
            'repetidosEnFichero' => $duplicatesInFile,
            'avisos' => $warnings,
            'estudiosSinReconocer' => $pending,
            'muestra' => $sample,
            'faltan' => $this->missingEssentials($mapping),
            'estudioDesde' => $mapping->studyFallbackHeader(),
        ];
    }

    /** $viewer: si está limitado a una empresa, se omiten las filas de la otra. */
    public function import(Table $table, string $fileName, array $options, int $userId, ?array $viewer = null): array
    {
        $mapping = $this->mapping($table, $options);
        $origin = $this->defaultOrigin($mapping, $options);
        $resolutions = $this->resolutions($options);
        $rowMapper = $this->rowMapper($mapping, $origin, $options, $resolutions);
        $now = $this->clock->now();

        if (filter_var($options['guardarAlias'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            foreach ($resolutions as $key => $studyId) {
                if ($studyId > 0) {
                    $this->catalog->saveAlias($key, $studyId, $now);
                }
            }
        }

        $importId = $this->imports->create([
            'usuario_id' => $userId,
            'archivo' => mb_substr($fileName, 0, 255),
            'origen' => $origin->value,
            'total_filas' => count($mapping->dataRows),
            'creado_en' => DbTime::toDb($now),
        ]);

        // Contactos antiguos: van a «Histórico» y no a pendientes (ver LeadService::ingest).
        $historical = filter_var($options['historico'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $report = ['historico' => $historical, 'creados' => 0, 'fusionados' => 0, 'reactivados' => 0, 'omitidos' => 0, 'conAvisos' => 0, 'incidencias' => []];
        foreach (array_chunk($mapping->dataRows, self::CHUNK, true) as $chunk) {
            $this->pdo->beginTransaction();
            try {
                foreach ($chunk as $index => $row) {
                    $this->importRow($rowMapper, $row, $index + 1, $userId, $importId, $historical, $viewer, $report);
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        $issues = array_slice($report['incidencias'], 0, self::MAX_ISSUES);
        $this->imports->save($importId, [
            'creados' => $report['creados'],
            'fusionados' => $report['fusionados'],
            'omitidos' => $report['omitidos'],
            'incidencias' => json_encode($issues, JSON_UNESCAPED_UNICODE),
        ]);

        return ['importacionId' => $importId, 'totalFilas' => count($mapping->dataRows)] + array_replace($report, [
            'incidencias' => $issues,
            'incidenciasOcultas' => max(0, count($report['incidencias']) - self::MAX_ISSUES),
        ]);
    }

    public function templateXlsx(): string
    {
        return (new ImportTemplate())->xlsx(
            array_column($this->catalog->studies(onlyActive: true), 'nombre'),
            array_map(static fn (LeadOrigin $o) => $o->label(), LeadOrigin::selectable())
        );
    }

    public function templateCsv(): string
    {
        return (new ImportTemplate())->csv();
    }

    public function history(): array
    {
        return $this->imports->latest();
    }

    private function importRow(RowMapper $rowMapper, array $row, int $rowNumber, int $userId, int $importId, bool $historical, ?array $viewer, array &$report): void
    {
        $this->pdo->exec('SAVEPOINT fila');
        try {
            $mapped = $rowMapper->map($row);
            $input = $mapped['input'];
            if (!$input->hasContactData()) {
                $report['omitidos']++;
                $report['incidencias'][] = ['fila' => $rowNumber, 'tipo' => 'omitida', 'mensaje' => 'Fila sin nombre, correo ni teléfono'];
                $this->pdo->exec('RELEASE SAVEPOINT fila');
                return;
            }

            $outside = $viewer === null ? null : $this->leadService->outsideCompany($input, $viewer);
            if ($outside !== null) {
                $report['omitidos']++;
                $report['incidencias'][] = ['fila' => $rowNumber, 'tipo' => 'omitida', 'mensaje' => $outside];
                $this->pdo->exec('RELEASE SAVEPOINT fila');
                return;
            }

            $result = $this->leadService->ingest($input, $userId, $importId, ActivityType::IMPORTADO, $historical);
            $report[$result['accion'] === LeadService::INGEST_CREATED ? 'creados' : 'fusionados']++;
            if ($result['reactivado']) {
                $report['reactivados']++;
            }
            if ($mapped['avisos'] !== []) {
                $report['conAvisos']++;
                $report['incidencias'][] = ['fila' => $rowNumber, 'tipo' => 'aviso', 'mensaje' => implode(' · ', $mapped['avisos']), 'leadId' => $result['id']];
            }
            $this->pdo->exec('RELEASE SAVEPOINT fila');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT fila');
            $report['omitidos']++;
            $report['incidencias'][] = ['fila' => $rowNumber, 'tipo' => 'error', 'mensaje' => 'No se pudo guardar: ' . $e->getMessage()];
        }
    }

    private function mapping(Table $table, array $options): ColumnMapping
    {
        if ($table->rows === []) {
            throw new \InvalidArgumentException('El fichero está vacío');
        }
        $mapping = $this->mapper->map($table);
        $overrides = is_array($options['columnas'] ?? null) ? $options['columnas'] : [];

        return $overrides === [] ? $mapping : $mapping->withOverrides($overrides);
    }

    private function defaultOrigin(ColumnMapping $mapping, array $options): LeadOrigin
    {
        $chosen = LeadOrigin::tryFrom(Input::text($options, 'origen'));
        if ($chosen !== null) {
            return $chosen;
        }

        return $mapping->looksLikeFacebook ? LeadOrigin::REDES_SOCIALES : LeadOrigin::OTRO;
    }

    /** @return array<string,int> */
    private function resolutions(array $options): array
    {
        $valid = array_flip($this->catalog->existingStudyIds(array_map('intval', array_values((array) ($options['estudios'] ?? [])))));
        $resolutions = [];
        foreach ((array) ($options['estudios'] ?? []) as $key => $studyId) {
            $studyId = (int) $studyId;
            if ($studyId === 0 || isset($valid[$studyId])) {
                $resolutions[(string) $key] = $studyId;
            }
        }

        return $resolutions;
    }

    private function rowMapper(ColumnMapping $mapping, LeadOrigin $origin, array $options, array $resolutions = []): RowMapper
    {
        $matcher = new StudyMatcher($this->catalog->studies(), $this->catalog->aliasMap());
        $centerId = isset($options['centroId']) && (int) $options['centroId'] > 0 && $this->catalog->centerExists((int) $options['centroId'])
            ? (int) $options['centroId']
            : null;

        return new RowMapper(
            $mapping,
            $resolutions === [] ? $matcher : $matcher->withResolutions($resolutions),
            new DateParser($this->timezone),
            $origin,
            $centerId,
        );
    }

    /** @return list<string> */
    private function missingEssentials(ColumnMapping $mapping): array
    {
        $missing = [];
        if (!$mapping->has(ImportField::NOMBRE) && !$mapping->has(ImportField::NOMBRE_COMPLETO)) {
            $missing[] = 'nombre';
        }
        if (!$mapping->has(ImportField::EMAIL)) {
            $missing[] = 'correo';
        }
        if (!$mapping->has(ImportField::TELEFONO)) {
            $missing[] = 'teléfono';
        }
        if (!$mapping->has(ImportField::ESTUDIO) && $mapping->studyFallbackHeader() === null) {
            $missing[] = 'estudio';
        }

        return $missing;
    }
}
