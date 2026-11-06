<?php

declare(strict_types=1);

namespace Leads\Domain\Import;

use Leads\Domain\Shared\Email;
use Leads\Domain\Shared\PhoneNumber;
use Leads\Domain\Shared\Text;

/**
 * Deduce qué columna es cada dato. Combina tres fuentes:
 *  1. nombres de cabecera conocidos (web, Facebook Lead Ads, Google Forms…),
 *  2. palabras clave dentro de la cabecera ("¿qué estudios te interesan?"),
 *  3. el contenido de la columna (si tiene arrobas es un correo).
 * Si el fichero no trae cabecera, se deduce todo por contenido.
 */
final class ColumnMapper
{
    private const SAMPLE_ROWS = 50;
    private const HEADER_SCAN_ROWS = 10;
    private const KEYWORD_RANK = 1000;

    private const EXACT = [
        'nombre' => ['nombre', 'name', 'first name', 'firstname', 'first', 'given name', 'nombre de pila', 'tu nombre', 'nombre alumno', 'nombre del alumno', 'nombre alumna'],
        'apellidos' => ['apellidos', 'apellido', 'last name', 'lastname', 'surname', 'family name', 'primer apellido', 'segundo apellido', 'tus apellidos'],
        'nombreCompleto' => ['nombre completo', 'full name', 'fullname', 'nombre y apellidos', 'nombre apellidos', 'nombre y apellido', 'contacto', 'alumno', 'alumna', 'your name', 'nombre completo del alumno'],
        'email' => ['email', 'e mail', 'correo', 'correo electronico', 'mail', 'email address', 'e mail address', 'direccion de correo', 'direccion de correo electronico', 'tu email', 'tu correo', 'tu correo electronico'],
        'telefono' => ['telefono', 'phone', 'phone number', 'movil', 'telefono movil', 'celular', 'tlf', 'tfno', 'tel', 'whatsapp', 'numero de telefono', 'mobile', 'mobile phone', 'numero de movil', 'telefono de contacto', 'work phone number', 'tu telefono', 'n telefono'],
        'ubicacion' => ['ubicacion', 'ciudad', 'city', 'localidad', 'provincia', 'province', 'state', 'location', 'poblacion', 'municipio', 'pais', 'country', 'region', 'codigo postal', 'cp', 'zip', 'zip code', 'post code', 'postal code', 'direccion', 'street address', 'residencia'],
        'estudio' => ['estudio', 'estudios', 'curso', 'cursos', 'programa', 'titulacion', 'course', 'program', 'formacion', 'especialidad', 'interes', 'estudio de interes', 'curso de interes', 'estudios de interes', 'grado', 'master', 'que quieres estudiar'],
        'origen' => ['origen', 'source', 'fuente', 'canal', 'platform', 'plataforma', 'utm source', 'lead source', 'medio'],
        // Ordenados por preferencia: si hay varias columnas del mismo campo, gana la primera de esta lista.
        'campana' => ['campana', 'campaign', 'campaign name', 'nombre de campana', 'utm campaign', 'form name', 'formulario', 'nombre del formulario', 'adset name', 'ad name', 'anuncio'],
        'fecha' => ['fecha', 'date', 'created time', 'created at', 'created', 'creado', 'creado el', 'fecha de creacion', 'fecha de alta', 'fecha registro', 'fecha de registro', 'timestamp', 'submitted at', 'fecha de entrada', 'fecha y hora', 'marca temporal', 'fecha de envio', 'submission date'],
        'comentarios' => ['comentarios', 'comentario', 'mensaje', 'message', 'observaciones', 'notas', 'nota', 'comments', 'comment', 'consulta', 'tu mensaje', 'dudas', 'pregunta', 'descripcion'],
    ];

    /** Palabras clave (sobre la cabecera normalizada), en orden de prioridad. */
    private const KEYWORDS = [
        'email' => ['mail', 'correo'],
        'telefono' => ['telefono', 'phone', 'movil', 'whatsapp', 'celular'],
        'estudio' => ['estudi', 'curso', 'program', 'titulac', 'formacion', 'interesa', 'grado', 'master', 'especialidad'],
        'nombreCompleto' => ['nombre y apellido', 'full name'],
        'apellidos' => ['apellido'],
        'nombre' => ['nombre', 'name'],
        'ubicacion' => ['ciudad', 'provincia', 'localidad', 'city', 'donde vives', 'ubicac', 'poblac', 'municipio', 'codigo postal'],
        'comentarios' => ['coment', 'mensaje', 'observ', 'consulta'],
        'fecha' => ['fecha', 'date'],
        'campana' => ['campan', 'campaign'],
    ];

    /** Cabeceras típicas de Facebook Lead Ads que delatan el origen. */
    private const FACEBOOK_HINTS = ['ad id', 'adset id', 'form id', 'is organic', 'ad name', 'adset name', 'form name', 'campaign id', 'inbox url'];

    private readonly DateParser $dates;

    public function __construct(?DateParser $dates = null)
    {
        $this->dates = $dates ?? new DateParser();
    }

    public function map(Table $table): ColumnMapping
    {
        $headerIndex = $this->findHeaderRow($table);
        $width = $table->width();

        if ($headerIndex === null) {
            $headers = array_map(static fn (int $i): string => 'Columna ' . ($i + 1), range(0, max(0, $width - 1)));
            $dataRows = $table->rows;
            $fields = array_fill(0, $width, ImportField::EXTRA);
            $confident = array_fill(0, $width, false);
            $ranks = array_fill(0, $width, self::KEYWORD_RANK);
        } else {
            $headers = array_map(static fn ($h) => Text::clean((string) $h), array_pad($table->rows[$headerIndex], $width, ''));
            foreach ($headers as $i => $header) {
                if ($header === '') {
                    $headers[$i] = 'Columna ' . ($i + 1);
                }
            }
            $dataRows = array_slice($table->rows, $headerIndex + 1, null, true);
            $fields = [];
            $confident = [];
            $ranks = [];
            foreach ($headers as $i => $header) {
                [$field, $isExact, $rank] = $this->fieldForHeader($header);
                $fields[$i] = $field;
                $confident[$i] = $isExact;
                $ranks[$i] = $rank;
            }
        }

        $sample = array_slice($dataRows, 0, self::SAMPLE_ROWS);
        $fields = $this->validateByContent($fields, $confident, $sample);
        $fields = $this->detectByContent($fields, $sample, $headerIndex === null);
        $fields = $this->resolveDuplicates($fields, $ranks);

        return new ColumnMapping(
            headers: $headers,
            fields: $fields,
            // Se conservan las claves: índice de la fila en el fichero (base 0).
            dataRows: array_filter($dataRows, static fn (array $row): bool => implode('', array_map('trim', $row)) !== ''),
            headerRow: $headerIndex,
            looksLikeFacebook: $this->looksLikeFacebook($headers),
        );
    }

    /**
     * @return array{0: ImportField, 1: bool, 2: int} campo, si la coincidencia es exacta
     *         y su prioridad (menor = preferida cuando varias columnas indican lo mismo)
     */
    public function fieldForHeader(string $header): array
    {
        $slug = Text::slug($header);
        if ($slug === '') {
            return [ImportField::EXTRA, false, self::KEYWORD_RANK];
        }
        foreach (self::EXACT as $field => $synonyms) {
            $rank = array_search($slug, $synonyms, true);
            if ($rank !== false) {
                return [ImportField::from($field), true, $rank];
            }
        }
        if (in_array($slug, self::FACEBOOK_HINTS, true) || in_array($slug, ['id', 'lead id', 'is organic', 'lead status'], true)) {
            return [ImportField::EXTRA, true, self::KEYWORD_RANK];
        }
        foreach (self::KEYWORDS as $field => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($slug, $keyword)) {
                    return [ImportField::from($field), false, self::KEYWORD_RANK];
                }
            }
        }

        return [ImportField::EXTRA, false, self::KEYWORD_RANK];
    }

    private function findHeaderRow(Table $table): ?int
    {
        $best = null;
        $bestScore = 0;
        foreach (array_slice($table->rows, 0, self::HEADER_SCAN_ROWS, true) as $index => $row) {
            // Una fila con correos o teléfonos es de datos, no de cabecera.
            if ($this->rowHasContactValues($row)) {
                break;
            }
            $score = 0;
            foreach ($row as $cell) {
                [$field, $exact] = $this->fieldForHeader((string) $cell);
                if ($field !== ImportField::EXTRA) {
                    $score += $exact ? 2 : 1;
                }
            }
            if ($score >= 2) {
                return $index;
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$index, $score];
            }
        }

        return $best;
    }

    private function rowHasContactValues(array $row): bool
    {
        foreach ($row as $cell) {
            $cell = trim((string) $cell);
            if (Email::isValid(Email::clean($cell)) || $this->looksLikePhone($cell)) {
                return true;
            }
        }

        return false;
    }

    /** Las coincidencias por palabra clave se confirman mirando los datos. */
    private function validateByContent(array $fields, array $confident, array $sample): array
    {
        foreach ($fields as $i => $field) {
            if ($confident[$i] ?? false) {
                continue;
            }
            $values = $this->columnValues($sample, $i);
            if ($values === []) {
                continue;
            }
            $ok = match ($field) {
                ImportField::EMAIL => $this->ratio($values, fn ($v) => str_contains($v, '@')) >= 0.5,
                ImportField::TELEFONO => $this->ratio($values, fn ($v) => $this->looksLikePhone($v)) >= 0.5,
                // Una pregunta tipo "¿Aceptas recibir información?" con respuestas sí/no no es un estudio.
                ImportField::FECHA => $this->ratio($values, fn ($v) => $this->dates->parse($v) !== null) >= 0.5,
                ImportField::ESTUDIO => $this->ratio($values, fn ($v) => !in_array(Text::slug($v), ['si', 'no', 'true', 'false', 'yes'], true)) >= 0.5,
                default => true,
            };
            if (!$ok) {
                $fields[$i] = ImportField::EXTRA;
            }
        }

        return $fields;
    }

    /** Si no se ha localizado el correo o el teléfono por cabecera, se busca por contenido. */
    private function detectByContent(array $fields, array $sample, bool $headerless): array
    {
        $has = static fn (ImportField $f): bool => in_array($f, $fields, true);

        foreach ([ImportField::EMAIL, ImportField::TELEFONO] as $wanted) {
            if ($has($wanted)) {
                continue;
            }
            foreach ($fields as $i => $field) {
                if ($field !== ImportField::EXTRA) {
                    continue;
                }
                $values = $this->columnValues($sample, $i);
                $test = $wanted === ImportField::EMAIL
                    ? fn ($v) => Email::isValid(Email::clean($v))
                    : fn ($v) => $this->looksLikePhone($v);
                if ($values !== [] && $this->ratio($values, $test) >= 0.6) {
                    $fields[$i] = $wanted;
                    break;
                }
            }
        }

        if ($headerless && !$has(ImportField::NOMBRE) && !$has(ImportField::NOMBRE_COMPLETO)) {
            foreach ($fields as $i => $field) {
                $values = $this->columnValues($sample, $i);
                if ($field === ImportField::EXTRA && $values !== [] && $this->ratio($values, static fn ($v) => (bool) preg_match('/^\p{L}[\p{L}\s\'.-]+$/u', $v)) >= 0.6) {
                    $fields[$i] = ImportField::NOMBRE_COMPLETO;
                    break;
                }
            }
        }

        return $fields;
    }

    /**
     * Los campos de valor único se quedan con la columna de mayor prioridad
     * (a igualdad, la primera); el resto pasa a datos extra.
     */
    private function resolveDuplicates(array $fields, array $ranks): array
    {
        $winner = [];
        foreach ($fields as $i => $field) {
            if (!$field->isSingle()) {
                continue;
            }
            $current = $winner[$field->value] ?? null;
            if ($current === null || $ranks[$i] < $ranks[$current]) {
                $winner[$field->value] = $i;
            }
        }
        foreach ($fields as $i => $field) {
            if ($field->isSingle() && $winner[$field->value] !== $i) {
                $fields[$i] = ImportField::EXTRA;
            }
        }
        // Con "nombre y apellidos" y "nombre" a la vez, la columna de nombre suelto sobra.
        if (isset($winner[ImportField::NOMBRE->value], $winner[ImportField::NOMBRE_COMPLETO->value])) {
            foreach ($fields as $i => $field) {
                if ($field === ImportField::NOMBRE_COMPLETO) {
                    $fields[$i] = ImportField::EXTRA;
                }
            }
        }

        return $fields;
    }

    public function looksLikePhone(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[a-z]{2,}/i', (string) preg_replace('/^p:/i', '', $value))) {
            return false;
        }
        if (preg_match('#^\d{1,4}[/-]\d{1,2}[/-]\d{1,4}#', $value)) {
            return false; // fecha
        }

        return PhoneNumber::tryParse($value) !== null;
    }

    private function looksLikeFacebook(array $headers): bool
    {
        $slugs = array_map(static fn (string $h): string => Text::slug($h), $headers);
        if (array_intersect($slugs, self::FACEBOOK_HINTS) !== []) {
            return true;
        }

        // Centro de clientes potenciales de Meta en español: «Formulario» con «Propietario» o «Número de WhatsApp».
        return in_array('formulario', $slugs, true) && array_intersect(['propietario', 'numero de whatsapp'], $slugs) !== [];
    }

    /** @return list<string> */
    private function columnValues(array $rows, int $index): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$index] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function ratio(array $values, callable $test): float
    {
        return count(array_filter($values, $test)) / max(1, count($values));
    }
}
