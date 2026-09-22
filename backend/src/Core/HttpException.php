<?php

declare(strict_types=1);

namespace Leads\Core;

/**
 * Error de dominio o de aplicación que debe llegar al cliente con un código HTTP.
 */
class HttpException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $status = 400, private readonly array $details = [])
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): array
    {
        return $this->details;
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self($message, 400, $details);
    }

    public static function unauthorized(string $message = 'Sesión no válida o caducada'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'No tienes permiso para esta acción'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'No encontrado'): self
    {
        return new self($message, 404);
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self($message, 409, $details);
    }

    public static function tooManyRequests(string $message): self
    {
        return new self($message, 429);
    }
}
