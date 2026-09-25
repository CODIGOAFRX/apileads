<?php

declare(strict_types=1);

namespace Leads\Tests\Integration;

use Leads\Core\Request;
use Leads\Core\Response;
use Leads\Core\Router;
use Leads\Http\Routes;

/**
 * Recorre TODAS las rutas de la API con datos mal formados (listas donde se
 * espera un texto, nulos, números, textos enormes, inyecciones, ids que no
 * existen…). Nada puede acabar en un error 500 ni en un aviso de PHP: cada
 * petición errónea debe responder con un 4xx y un mensaje, y la aplicación
 * seguir funcionando después.
 */
final class ApiRobustnessTest extends DatabaseTestCase
{
    /** Campos que usa alguna ruta; se prueban todos en todas. */
    private const FIELDS = [
        'nombre', 'apellidos', 'email', 'telefono', 'ubicacion', 'interes', 'estudioIds', 'origen', 'centroId', 'comentarios',
        'resultado', 'nota', 'fecha', 'motivo', 'texto', 'asunto', 'html', 'cuerpoHtml', 'estudioId', 'clave', 'password',
        'actual', 'nueva', 'rol', 'activo', 'alias', 'color', 'tipo', 'area', 'orden', 'centroIds', 'ciudad', 'provincia',
        'usuario', 'smtpHost', 'smtpPuerto', 'smtpCifrado', 'imapHost', 'imapPuerto', 'guardarEnviados', 'carpetaEnviados',
        'verificarCertificado', 'opciones', 'fusionar',
    ];

    private const QUERY = ['q', 'bandeja', 'pagina', 'porPagina', 'estudio', 'tipo', 'origen', 'desde', 'hasta', 'nombre', 'email',
        'telefono', 'ubicacion', 'sinEstudio', 'formato', 'fecha', 'usuario', 'vista', 'orden'];

    private Router $router;
    private string $adminToken;
    private string $comercialToken;
    private int $leadId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = Routes::build($this->app);
        $this->adminToken = $this->login('admin@test.es');
        $this->comercialToken = $this->login('marta@test.es');
        $this->leadId = $this->lead(['nombre' => 'Eva', 'email' => 'eva@example.com', 'telefono' => '612345678', 'estudioIds' => [2]]);

        // Cualquier aviso de PHP (conversión de array a texto, índice inexistente…) cuenta como fallo.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }

    /** Valores con los que se rellena cada campo. */
    public static function badValues(): array
    {
        return [
            'lista' => [['a', 'b']],
            'lista anidada' => [[['x' => ['y']]]],
            'nulo' => [null],
            'número' => [123456789],
            'número enorme' => [PHP_INT_MAX],
            'número en texto enorme' => ['99999999999999999999999'],
            'negativo' => [-2147483649],
            'decimal' => [-1.5],
            'booleano' => [true],
            'texto enorme' => [str_repeat('ñ', 20000)],
            'inyección' => ["' OR 1=1; DROP TABLE leads; -- <script>alert(1)</script>{{nombre}}\r\nBcc: x@y.z"],
            'vacío' => [''],
            'caracteres raros' => ["\u{0000}\u{FEFF}😀\x80\xFF"],
        ];
    }

    /** Se recorren todas las rutas con cada tipo de valor malo, como administración y como comercial. */
    #[\PHPUnit\Framework\Attributes\DataProvider('badValues')]
    public function testNingunaRutaFallaConDatosMalFormados(mixed $value): void
    {
        $body = array_fill_keys(self::FIELDS, $value);
        $query = array_fill_keys(self::QUERY, $value);
        $failures = [];

        foreach ($this->router->routes() as $route) {
            foreach ($this->pathsFor($route['pattern']) as $path) {
                foreach ([$this->adminToken, $this->comercialToken] as $token) {
                    // Cada ruta parte del mismo estado: lo que hace una (borrar un estudio,
                    // descartar el lead…) no puede esconder un fallo de la siguiente.
                    $this->pdo->beginTransaction();
                    try {
                        $response = $this->call($route['method'], $path, $body, $token, $query);
                    } finally {
                        $this->pdo->rollBack();
                    }
                    if ($response->status() >= 500) {
                        $failures[] = "{$route['method']} {$path} → {$response->status()}: " . ($response->data()['error'] ?? $response->body());
                    }
                }
            }
        }

        self::assertSame([], $failures);
        // Y la aplicación sigue viva después de todo lo anterior.
        self::assertSame(200, $this->call('GET', '/api/leads', [], $this->login('admin@test.es'))->status());
    }

    public function testIdsQueNoExistenOInvalidosResponden404O422(): void
    {
        $failures = [];
        foreach ($this->router->routes() as $route) {
            if (!str_contains($route['pattern'], '{')) {
                continue;
            }
            foreach (['999999', '0', '-1', 'abc', '1e3', '%00'] as $id) {
                $path = (string) preg_replace('/\{\w+\}/', $id, $route['pattern']);
                $response = $this->call($route['method'], $path, [], $this->adminToken);
                if ($response->status() >= 500 || $response->status() === 200 && $route['method'] !== 'GET') {
                    $failures[] = "{$route['method']} {$path} → {$response->status()}";
                }
            }
        }

        self::assertSame([], $failures, 'un id inexistente nunca debe «funcionar» ni romper nada');
    }

    public function testLasRutasProtegidasPidenSesionYPermisos(): void
    {
        foreach ($this->router->routes() as $route) {
            $path = $this->pathsFor($route['pattern'])[0];
            if ($route['access'] === Router::PUBLIC) {
                continue;
            }
            self::assertSame(401, $this->call($route['method'], $path)->status(), "{$route['method']} {$path} sin sesión");
            self::assertSame(401, $this->call($route['method'], $path, [], 'x.y.z')->status(), "{$route['method']} {$path} con token falso");
            if ($route['access'] === Router::ADMIN) {
                self::assertSame(403, $this->call($route['method'], $path, [], $this->comercialToken)->status(), "{$route['method']} {$path} como comercial");
            }
        }
    }

    public function testRutasYMetodosDesconocidos(): void
    {
        self::assertSame(404, $this->call('GET', '/api/no-existe', [], $this->adminToken)->status());
        self::assertSame(404, $this->call('GET', '/api/leads/1/../../usuarios', [], $this->adminToken)->status());
        self::assertSame(405, $this->call('PATCH', '/api/leads', [], $this->adminToken)->status());
    }

    /** Cada {parámetro} con un id real (de otro usuario en /usuarios para no tocar al administrador). */
    private function pathsFor(string $pattern): array
    {
        $real = str_starts_with($pattern, '/api/usuarios/') ? (string) $this->comercial['id'] : (string) $this->leadId;

        return [(string) preg_replace('/\{\w+\}/', $real, $pattern)];
    }

    private function login(string $email): string
    {
        return $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => 'ClaveSegura123'])->data()['token'];
    }

    private function call(string $method, string $path, array $body = [], ?string $token = null, array $query = []): Response
    {
        $headers = $token ? ['authorization' => 'Bearer ' . $token] : [];

        return $this->router->dispatch(new Request($method, $path, $query, $body, $headers, [], '127.0.0.1'));
    }
}
