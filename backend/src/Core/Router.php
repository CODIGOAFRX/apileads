<?php

declare(strict_types=1);

namespace Leads\Core;

use Leads\Domain\User\Role;

/**
 * Router mínimo con parámetros {nombre} y control de acceso por ruta.
 *
 * Cada ruta declara quién puede usarla: pública, cualquier usuario autenticado
 * o solo administradores. La autenticación la resuelve un callable inyectado,
 * así el router no depende del servicio JWT.
 */
final class Router
{
    public const PUBLIC = 'public';
    public const AUTH = 'auth';
    public const ADMIN = 'admin';

    /** @var list<array{method:string, regex:string, handler:callable, access:string}> */
    private array $routes = [];

    /** @param callable(?string):?array $authenticate */
    public function __construct(private $authenticate)
    {
    }

    public function get(string $pattern, callable $handler, string $access = self::AUTH): void
    {
        $this->add('GET', $pattern, $handler, $access);
    }

    public function post(string $pattern, callable $handler, string $access = self::AUTH): void
    {
        $this->add('POST', $pattern, $handler, $access);
    }

    public function put(string $pattern, callable $handler, string $access = self::AUTH): void
    {
        $this->add('PUT', $pattern, $handler, $access);
    }

    public function delete(string $pattern, callable $handler, string $access = self::AUTH): void
    {
        $this->add('DELETE', $pattern, $handler, $access);
    }

    public function add(string $method, string $pattern, callable $handler, string $access = self::AUTH): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'access' => $access];
    }

    public function dispatch(Request $request): Response
    {
        try {
            return $this->handle($request);
        } catch (HttpException $e) {
            return Response::error($e->getMessage(), $e->status(), $e->details());
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            error_log('[apileads] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $message = Env::bool('APP_DEBUG') ? $e->getMessage() : 'Error interno del servidor';
            return Response::error($message, 500);
        }
    }

    private function handle(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path(), $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method()) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $request = $this->authorize($request->withRouteParams($params), $route['access']);

            return ($route['handler'])($request);
        }

        return $pathMatched
            ? Response::error('Método no permitido', 405)
            : Response::error('Ruta no encontrada', 404);
    }

    private function authorize(Request $request, string $access): Request
    {
        if ($access === self::PUBLIC) {
            return $request;
        }

        $user = ($this->authenticate)($request->bearerToken());
        if ($user === null) {
            throw HttpException::unauthorized();
        }
        if ($access === self::ADMIN && ($user['rol'] ?? null) !== Role::ADMIN->value) {
            throw HttpException::forbidden();
        }

        return $request->withUser($user);
    }
}
