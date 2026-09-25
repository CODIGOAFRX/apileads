<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

use Leads\Core\Input;
use Leads\Domain\Shared\Text;

/**
 * Criterios del buscador. Todos los textos se buscan por "contiene", sin
 * distinguir tildes ni mayúsculas; el teléfono se compara solo por dígitos.
 */
final class LeadSearch
{
    public const SORTS = ['entrada', 'nombre', 'proxima', 'ultima-llamada'];

    /** @param list<int> $studyIds */
    public function __construct(
        public readonly Inbox $inbox = Inbox::PENDIENTES,
        public readonly string $text = '',
        public readonly string $name = '',
        public readonly string $email = '',
        public readonly string $phoneDigits = '',
        public readonly array $studyIds = [],
        public readonly ?string $studyType = null,
        public readonly bool $withoutStudy = false,
        public readonly ?string $origin = null,
        public readonly ?int $centerId = null,
        public readonly string $location = '',
        public readonly ?string $status = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly string $sort = 'entrada',
        public readonly string $direction = 'desc',
        public readonly int $page = 1,
        public readonly int $perPage = 50,
    ) {
    }

    public static function fromQuery(array $q): self
    {
        $date = static fn ($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $sort = in_array($q['orden'] ?? '', self::SORTS, true) ? $q['orden'] : 'entrada';

        return new self(
            inbox: Inbox::tryFrom(Input::text($q, 'bandeja')) ?? Inbox::PENDIENTES,
            text: mb_substr(trim(Input::text($q, 'q')), 0, 120),
            name: mb_substr(trim(Input::text($q, 'nombre')), 0, 120),
            email: mb_substr(trim(Input::text($q, 'email')), 0, 120),
            phoneDigits: self::digitsOf(Input::text($q, 'telefono')),
            studyIds: Input::ids($q['estudio'] ?? []),
            studyType: in_array($q['tipo'] ?? null, ['GRADO', 'CFGS', 'MASTER', 'CURSO', 'OTRO'], true) ? $q['tipo'] : null,
            withoutStudy: filter_var($q['sinEstudio'] ?? false, FILTER_VALIDATE_BOOLEAN),
            origin: LeadOrigin::tryFrom(Input::text($q, 'origen'))?->value,
            centerId: Input::id($q['centro'] ?? null),
            location: mb_substr(trim(Input::text($q, 'ubicacion')), 0, 120),
            status: LeadStatus::tryFrom(Input::text($q, 'estado'))?->value,
            from: $date($q['desde'] ?? null),
            to: $date($q['hasta'] ?? null),
            sort: $sort,
            direction: strtolower(Input::text($q, 'dir')) === 'asc' ? 'asc' : 'desc',
            page: min(100000, Input::id($q['pagina'] ?? null) ?? 1),
            perPage: min(200, max(10, Input::id($q['porPagina'] ?? null) ?? 50)),
        );
    }

    /**
     * Palabras del buscador general. "andres garcia" exige que aparezcan las dos,
     * en cualquier orden y en cualquier campo.
     *
     * @return list<string>
     */
    public function words(): array
    {
        $normalized = Text::normalize($this->text);
        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    /** Dígitos del buscador general si el término parece un teléfono ("454", "+34 600"). */
    public function textDigits(): ?string
    {
        if (!preg_match('/^[\d\s+().-]{3,}$/', $this->text)) {
            return null;
        }
        $digits = self::digitsOf($this->text);

        return strlen($digits) >= 3 ? $digits : null;
    }

    /** Solo dígitos; el prefijo internacional 00 se quita porque se guarda sin él. */
    public static function digitsOf(string $value): string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);
        if (strlen($digits) > 9 && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return substr($digits, 0, 20);
    }
}
