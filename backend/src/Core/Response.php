<?php

declare(strict_types=1);

namespace Leads\Core;

final class Response
{
    private function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'], $body);
    }

    public static function noContent(): self
    {
        return new self(204, [], '');
    }

    public static function error(string $message, int $status, array $details = []): self
    {
        $payload = ['error' => $message];
        if ($details !== []) {
            $payload['detalles'] = $details;
        }

        return self::json($payload, $status);
    }

    public static function download(string $content, string $filename, string $contentType): self
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'descarga';

        return new self(200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => sprintf(
                'attachment; filename="%s"; filename*=UTF-8\'\'%s',
                $ascii,
                rawurlencode($filename)
            ),
            'Cache-Control' => 'no-store',
        ], $content);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** Cuerpo JSON decodificado (útil en pruebas). */
    public function data(): mixed
    {
        return json_decode($this->body, true);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
