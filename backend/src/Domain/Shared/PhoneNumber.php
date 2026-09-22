<?php

declare(strict_types=1);

namespace Leads\Domain\Shared;

/**
 * Teléfono normalizado a formato internacional (solo dígitos, con prefijo de país).
 *
 * Acepta lo que llega de formularios y de Facebook Lead Ads: "p:+34600123454",
 * "600 12 34 54", "0034-600-123-454", "(+34) 600.12.34.54", etc.
 * Los números españoles de 9 cifras reciben el prefijo 34.
 */
final class PhoneNumber
{
    private const MIN_DIGITS = 9;
    private const MAX_DIGITS = 15;

    private function __construct(private readonly string $digits, private readonly string $original)
    {
    }

    public static function tryParse(?string $raw, string $defaultCountryCode = '34'): ?self
    {
        $original = Text::clean($raw);
        if ($original === '') {
            return null;
        }

        // Facebook antepone "p:" y Excel a veces deja el número como 3.4600123454E+10
        $value = (string) preg_replace('/^p:\s*/i', '', $original);
        if (preg_match('/^\d(\.\d+)?E\+\d+$/i', $value)) {
            $value = number_format((float) $value, 0, '', '');
        }
        // Si vienen dos números ("600111222 / 958111222") nos quedamos con el primero.
        $value = preg_split('#\s*(?:/|,|;|\||\by\b|\bo\b)\s*#u', $value)[0] ?? $value;

        $international = str_starts_with(ltrim($value), '+');
        $digits = (string) preg_replace('/\D+/', '', $value);

        if (!$international && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $international = true;
        }

        // Un número nacional de 9 cifras recibe el prefijo del país; el resto se guarda tal cual.
        if (!$international && strlen($digits) === 9) {
            $digits = $defaultCountryCode . $digits;
        }

        if (strlen($digits) < self::MIN_DIGITS || strlen($digits) > self::MAX_DIGITS) {
            return null;
        }

        return new self($digits, $original);
    }

    /** Dígitos con prefijo de país, p. ej. 34600123454 (sirve para wa.me y para deduplicar). */
    public function digits(): string
    {
        return $this->digits;
    }

    public function original(): string
    {
        return $this->original;
    }

    public function isSpanish(): bool
    {
        return strlen($this->digits) === 11 && str_starts_with($this->digits, '34');
    }

    /** Formato legible: +34 600 12 34 54 */
    public function formatted(): string
    {
        if ($this->isSpanish()) {
            $n = substr($this->digits, 2);
            return sprintf('+34 %s %s %s %s', substr($n, 0, 3), substr($n, 3, 2), substr($n, 5, 2), substr($n, 7, 2));
        }

        return '+' . $this->digits;
    }

    public function whatsappUrl(string $message = ''): string
    {
        $url = 'https://wa.me/' . $this->digits;
        return $message === '' ? $url : $url . '?text=' . rawurlencode($message);
    }
}
