<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

use DateTimeImmutable;
use Leads\Core\Input;
use Leads\Domain\Shared\Email;
use Leads\Domain\Shared\PhoneNumber;
use Leads\Domain\Shared\Text;

/**
 * Datos de contacto de un lead ya limpios, vengan de un formulario o de una
 * fila importada. Ningún campo es obligatorio por sí solo: basta con que haya
 * alguna forma de identificar o contactar a la persona.
 */
final class LeadInput
{
    /** @param list<int> $studyIds */
    public function __construct(
        public readonly string $firstName = '',
        public readonly string $lastName = '',
        public readonly ?string $email = null,
        public readonly ?PhoneNumber $phone = null,
        public readonly ?string $rawPhone = null,
        public readonly ?string $location = null,
        public readonly ?string $interestText = null,
        public readonly array $studyIds = [],
        public readonly LeadOrigin $origin = LeadOrigin::OTRO,
        public readonly ?string $campaign = null,
        public readonly ?string $comments = null,
        public readonly array $extra = [],
        public readonly ?DateTimeImmutable $sourceDate = null,
        public readonly ?int $centerId = null,
    ) {
    }

    /** Construye la entrada a partir de valores sueltos, limpiándolos. */
    public static function fromValues(array $v): self
    {
        $rawPhone = Text::nullIfEmpty(Input::optionalText($v, 'telefono'));
        $studyIds = Input::ids($v['estudioIds'] ?? []);

        return new self(
            firstName: Text::truncate(Text::properName(Input::text($v, 'nombre')), 160) ?? '',
            lastName: Text::truncate(Text::properName(Input::text($v, 'apellidos')), 160) ?? '',
            email: Text::truncate(Email::clean(Input::optionalText($v, 'email')), 190),
            phone: PhoneNumber::tryParse($rawPhone),
            rawPhone: Text::truncate($rawPhone, 40),
            location: Text::truncate(Text::nullIfEmpty(Input::optionalText($v, 'ubicacion')), 190),
            interestText: Text::truncate(Text::nullIfEmpty(Input::optionalText($v, 'interes')), 255),
            studyIds: $studyIds,
            origin: ($v['origen'] ?? null) instanceof LeadOrigin
                ? $v['origen']
                : (LeadOrigin::fromText(Input::optionalText($v, 'origen')) ?? LeadOrigin::OTRO),
            campaign: Text::truncate(Text::nullIfEmpty(Input::optionalText($v, 'campana')), 190),
            comments: Text::nullIfEmpty(Input::optionalText($v, 'comentarios')),
            extra: is_array($v['extra'] ?? null) ? $v['extra'] : [],
            sourceDate: $v['fechaOrigen'] ?? null,
            centerId: Input::id($v['centroId'] ?? null),
        );
    }

    public function hasContactData(): bool
    {
        return $this->firstName !== '' || $this->lastName !== '' || $this->email !== null || $this->rawPhone !== null;
    }

    public function hasValidEmail(): bool
    {
        return Email::isValid($this->email);
    }

    /** Teléfono a guardar: formateado si se ha podido interpretar, o tal cual llegó. */
    public function displayPhone(): ?string
    {
        return $this->phone?->formatted() ?? $this->rawPhone;
    }

    /** @return list<string> avisos no bloqueantes sobre la calidad del dato */
    public function warnings(): array
    {
        $warnings = [];
        if ($this->email !== null && !$this->hasValidEmail()) {
            $warnings[] = "Correo con formato dudoso: {$this->email}";
        }
        if ($this->rawPhone !== null && $this->phone === null) {
            $warnings[] = "Teléfono no reconocido: {$this->rawPhone}";
        }
        if ($this->email === null && $this->rawPhone === null) {
            $warnings[] = 'Sin correo ni teléfono';
        }

        return $warnings;
    }

    public function nameSearchText(): string
    {
        return mb_substr(Text::normalize($this->firstName . ' ' . $this->lastName), 0, 330);
    }

    public function searchText(): string
    {
        return mb_substr(Text::normalize(implode(' ', array_filter([
            $this->firstName,
            $this->lastName,
            $this->email,
            $this->location,
            $this->interestText,
            $this->campaign,
        ]))), 0, 700);
    }
}
