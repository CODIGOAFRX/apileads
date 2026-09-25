<?php

declare(strict_types=1);

namespace Leads\Core;

/**
 * Petición HTTP inmutable. Se construye desde las superglobales en producción
 * y a mano en las pruebas.
 */
final class Request
{
    private array $routeParams = [];
    private ?array $user = null;

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $files = [],
        private readonly string $ip = 'unknown',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = self::collectHeaders();
        $contentType = strtolower($headers['content-type'] ?? '');

        $body = $_POST;
        if (str_contains($contentType, 'application/json')) {
            $body = self::decodeJson(file_get_contents('php://input') ?: '');
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        return new self(
            $method,
            $path,
            $_GET,
            $body,
            $headers,
            $_FILES,
            filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown',
        );
    }

    public static function decodeJson(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw HttpException::badRequest('El cuerpo de la petición no contiene JSON válido');
        }

        return $data;
    }

    private static function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }
        // Apache/Plesk a veces solo expone la cabecera Authorization tras un rewrite.
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (!isset($headers['authorization']) && $auth) {
            $headers['authorization'] = (string) $auth;
        }

        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function allQuery(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function body(): array
    {
        return $this->body;
    }

    /** Campo del cuerpo como texto ('' si no viene). Ver Input. */
    public function text(string $key, string $default = ''): string
    {
        return Input::text($this->body, $key, $default);
    }

    /** Campo del cuerpo como texto, o null si no viene. */
    public function optionalText(string $key): ?string
    {
        return Input::optionalText($this->body, $key);
    }

    /** Parámetro de la URL como texto, o null si no viene. */
    public function queryText(string $key): ?string
    {
        return Input::optionalText($this->query, $key);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function file(string $name): ?array
    {
        $file = $this->files[$name] ?? null;
        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization') ?? '';
        return preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) ? trim($m[1]) : null;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;
        return $clone;
    }

    public function param(string $name): string
    {
        return (string) ($this->routeParams[$name] ?? '');
    }

    public function intParam(string $name): int
    {
        return (int) $this->param($name);
    }

    public function withUser(array $user): self
    {
        $clone = clone $this;
        $clone->user = $user;
        return $clone;
    }

    /** Usuario autenticado (lo pone el Router tras validar el token). */
    public function user(): array
    {
        return $this->user ?? throw HttpException::unauthorized();
    }

    public function userId(): int
    {
        return (int) $this->user()['id'];
    }
}
