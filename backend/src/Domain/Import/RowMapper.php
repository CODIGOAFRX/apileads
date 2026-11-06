<?php

declare(strict_types=1);

namespace Leads\Domain\Import;

use Leads\Domain\Lead\LeadInput;
use Leads\Domain\Lead\LeadOrigin;
use Leads\Domain\Shared\Text;

/** Convierte una fila del fichero en un {@see LeadInput} según el mapeo de columnas. */
final class RowMapper
{
    public function __construct(
        private readonly ColumnMapping $mapping,
        private readonly StudyMatcher $studies,
        private readonly DateParser $dates,
        private readonly LeadOrigin $defaultOrigin,
        private readonly ?int $centerId = null,
    ) {
    }

    /**
     * @param list<string> $row
     * @return array{input: LeadInput, pendientes: list<array{clave:string, texto:string}>, avisos: list<string>}
     */
    public function map(array $row): array
    {
        $values = [];
        $extra = [];
        $comments = [];
        foreach ($this->mapping->fields as $i => $field) {
            $value = Text::clean((string) ($row[$i] ?? ''));
            if ($value === '' || $field === ImportField::IGNORAR) {
                continue;
            }
            $header = $this->mapping->headers[$i] ?? ('Columna ' . ($i + 1));
            match ($field) {
                ImportField::EXTRA => $extra[$header] = $value,
                ImportField::COMENTARIOS => $comments[] = count($this->fieldColumns(ImportField::COMENTARIOS)) > 1 ? "{$header}: {$value}" : $value,
                default => $values[$field->value][] = $value,
            };
        }

        $first = static fn (ImportField $f): ?string => $values[$f->value][0] ?? null;
        $joined = static fn (ImportField $f, string $glue): ?string => isset($values[$f->value]) ? implode($glue, array_unique($values[$f->value])) : null;

        $firstName = $first(ImportField::NOMBRE) ?? '';
        $lastName = $joined(ImportField::APELLIDOS, ' ') ?? '';
        $fullName = $first(ImportField::NOMBRE_COMPLETO);
        if ($firstName === '' && $fullName !== null) {
            $firstName = $fullName;
        }

        $interest = $joined(ImportField::ESTUDIO, ', ');
        $fromCampaign = $this->mapping->studyFallbackHeader() !== null;
        if ($fromCampaign) {
            $interest = $first(ImportField::CAMPANA);
        }
        $match = $this->studies->match($interest);
        if ($fromCampaign && $match['pendientes'] === []) {
            // La campaña ya se guarda aparte: como «interés» solo si queda por decidir el estudio.
            $interest = null;
        }

        $warnings = [];
        $rawDate = $first(ImportField::FECHA);
        $date = $this->dates->parse($rawDate);
        if ($rawDate !== null && $date === null) {
            $extra['Fecha original'] = $rawDate;
            $warnings[] = "Fecha no reconocida: {$rawDate}";
        }

        $origin = LeadOrigin::fromText($first(ImportField::ORIGEN));
        if ($origin === null || $origin === LeadOrigin::OTRO) {
            if ($first(ImportField::ORIGEN) !== null) {
                $extra['Origen original'] = $first(ImportField::ORIGEN);
            }
            $origin = $this->defaultOrigin;
        }

        $input = LeadInput::fromValues([
            'nombre' => $firstName,
            'apellidos' => $lastName,
            'email' => $first(ImportField::EMAIL),
            'telefono' => $first(ImportField::TELEFONO),
            'ubicacion' => $joined(ImportField::UBICACION, ', '),
            'interes' => $interest,
            'estudioIds' => $match['ids'],
            'origen' => $origin,
            'campana' => $first(ImportField::CAMPANA),
            'comentarios' => $comments === [] ? null : implode("\n", $comments),
            'extra' => $extra,
            'fechaOrigen' => $date,
            'centroId' => $this->centerId,
        ]);

        return [
            'input' => $input,
            'pendientes' => $match['pendientes'],
            'avisos' => array_merge($warnings, $input->warnings()),
        ];
    }

    /** @return list<int> */
    private function fieldColumns(ImportField $field): array
    {
        return array_keys(array_filter($this->mapping->fields, static fn (ImportField $f): bool => $f === $field));
    }
}
