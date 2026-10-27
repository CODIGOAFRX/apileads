<?php

declare(strict_types=1);

namespace Leads\Http;

use Leads\Application\AuditService;
use Leads\Core\App;
use Leads\Core\Input;
use Leads\Core\Request;
use Leads\Core\Response;
use Leads\Core\Router;
use Leads\Http\Controller\AuditController;
use Leads\Http\Controller\AuthController;
use Leads\Http\Controller\CatalogController;
use Leads\Http\Controller\ImportController;
use Leads\Http\Controller\LeadController;
use Leads\Http\Controller\MailAccountController;
use Leads\Http\Controller\MailController;

/** Tabla de rutas de la API. */
final class Routes
{
    public static function build(App $app): Router
    {
        $router = new Router(static fn (?string $token): ?array => $app->auth()->userFromToken($token));
        $c = static fn (string $class, string $method): \Closure => static fn ($request) => (new $class($app))->{$method}($request);
        // Cambios de ajustes: si salen bien, quedan en el registro de actividad.
        // {campo} se sustituye por ese dato del formulario (nombre, email…).
        $logged = static fn (string $summary, \Closure $handler): \Closure => static function (Request $request) use ($app, $summary, $handler): Response {
            $response = $handler($request);
            if ($response->status() < 400) {
                $text = (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($request): string {
                    $value = $request->input($m[1]);
                    return is_scalar($value) ? (string) Input::toText($value) : '';
                }, $summary);
                $app->audit()->record($request->userId(), AuditService::AJUSTES, $text, ['ruta' => $request->method() . ' ' . $request->path()], null, $request->ip());
            }

            return $response;
        };

        $router->get('/api/health', static fn () => Response::json(['ok' => true]), Router::PUBLIC);

        // Sesión
        $router->post('/api/auth/login', $c(AuthController::class, 'login'), Router::PUBLIC);
        $router->get('/api/auth/me', $c(AuthController::class, 'me'));
        $router->post('/api/auth/password', $c(AuthController::class, 'changePassword'));

        // Leads
        $router->get('/api/leads', $c(LeadController::class, 'index'));
        $router->post('/api/leads', $c(LeadController::class, 'store'));
        $router->get('/api/leads/contadores', $c(LeadController::class, 'counters'));
        $router->get('/api/leads/exportar', $c(LeadController::class, 'export'));
        $router->get('/api/leads/{id}', $c(LeadController::class, 'show'));
        $router->put('/api/leads/{id}', $c(LeadController::class, 'update'));
        $router->post('/api/leads/{id}/bloqueo', $c(LeadController::class, 'lock'));
        $router->post('/api/leads/{id}/marcar', $c(LeadController::class, 'dial'));
        $router->delete('/api/leads/{id}/bloqueo', $c(LeadController::class, 'unlock'));
        $router->post('/api/leads/{id}/llamada', $c(LeadController::class, 'call'));
        $router->post('/api/leads/{id}/llamada-agendada', $c(LeadController::class, 'scheduleCall'));
        $router->post('/api/leads/{id}/interesado', $c(LeadController::class, 'interested'));
        $router->post('/api/leads/{id}/cita', $c(LeadController::class, 'appointment'));
        $router->post('/api/leads/{id}/matricula', $c(LeadController::class, 'enroll'));
        $router->post('/api/leads/{id}/descarte', $c(LeadController::class, 'discard'));
        $router->post('/api/leads/{id}/reapertura', $c(LeadController::class, 'reopen'));
        $router->post('/api/leads/{id}/notas', $c(LeadController::class, 'note'));
        $router->post('/api/leads/{id}/whatsapp', $c(LeadController::class, 'whatsapp'));
        $router->get('/api/leads/{id}/correo', $c(MailController::class, 'preview'));
        $router->post('/api/leads/{id}/correo', $c(MailController::class, 'send'));

        // Importación
        $router->get('/api/importaciones', $c(ImportController::class, 'history'));
        $router->get('/api/importaciones/plantilla', $c(ImportController::class, 'template'));
        $router->post('/api/importaciones/previsualizar', $c(ImportController::class, 'preview'));
        $router->post('/api/importaciones', $c(ImportController::class, 'import'));

        // Catálogo (lectura para todos, cambios solo administración)
        $router->get('/api/catalogo', $c(CatalogController::class, 'index'));
        // Cualquiera puede crear un estudio que falte (cursos cortos…); cambiarlo, solo administración.
        $router->post('/api/estudios', $logged('Crea el estudio «{nombre}»', $c(CatalogController::class, 'createStudy')));
        $router->put('/api/estudios/{id}', $logged('Edita el estudio «{nombre}»', $c(CatalogController::class, 'updateStudy')), Router::ADMIN);
        $router->delete('/api/estudios/{id}', $logged('Borra un estudio', $c(CatalogController::class, 'deleteStudy')), Router::ADMIN);
        $router->post('/api/estudios/{id}/alias', $logged('Añade el alias «{alias}» a un estudio', $c(CatalogController::class, 'addAlias')), Router::ADMIN);
        $router->delete('/api/alias/{id}', $logged('Borra un alias de estudio', $c(CatalogController::class, 'deleteAlias')), Router::ADMIN);
        $router->post('/api/centros', $logged('Crea el centro «{nombre}»', $c(CatalogController::class, 'createCenter')), Router::ADMIN);
        $router->put('/api/centros/{id}', $logged('Edita el centro «{nombre}»', $c(CatalogController::class, 'updateCenter')), Router::ADMIN);

        // Correos
        $router->get('/api/plantillas', $c(MailController::class, 'templates'), Router::ADMIN);
        $router->put('/api/plantillas', $logged('Guarda la plantilla de correo «{asunto}»', $c(MailController::class, 'saveTemplate')), Router::ADMIN);
        $router->delete('/api/plantillas/{estudioId}', $logged('Quita la plantilla de correo de un estudio', $c(MailController::class, 'deleteTemplate')), Router::ADMIN);
        $router->post('/api/plantillas/{id}/adjunto', $logged('Sube un dossier a una plantilla de correo', $c(MailController::class, 'attach')), Router::ADMIN);
        $router->delete('/api/plantillas/{id}/adjunto', $logged('Quita el dossier de una plantilla de correo', $c(MailController::class, 'detach')), Router::ADMIN);
        $router->get('/api/adjuntos/{id}', $c(MailController::class, 'attachment'));

        // Usuarios
        $router->get('/api/usuarios', $c(AuthController::class, 'listUsers'), Router::ADMIN);
        $router->post('/api/usuarios', $logged('Crea el usuario {email}', $c(AuthController::class, 'createUser')), Router::ADMIN);
        $router->put('/api/usuarios/{id}', $logged('Edita el usuario {email}', $c(AuthController::class, 'updateUser')), Router::ADMIN);
        $router->get('/api/usuarios/{id}/correo', $c(MailAccountController::class, 'show'), Router::ADMIN);
        $router->put('/api/usuarios/{id}/correo', $logged('Configura el buzón de envío {email}', $c(MailAccountController::class, 'save')), Router::ADMIN);
        $router->delete('/api/usuarios/{id}/correo', $logged('Quita el buzón de envío de un usuario', $c(MailAccountController::class, 'delete')), Router::ADMIN);
        $router->post('/api/usuarios/{id}/correo/prueba', $c(MailAccountController::class, 'test'), Router::ADMIN);

        // Registro de actividad
        $router->get('/api/actividad', $c(AuditController::class, 'day'), Router::ADMIN);
        $router->get('/api/actividad/exportar', $c(AuditController::class, 'export'), Router::ADMIN);

        return $router;
    }
}
