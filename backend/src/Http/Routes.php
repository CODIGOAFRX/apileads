<?php

declare(strict_types=1);

namespace Leads\Http;

use Leads\Core\App;
use Leads\Core\Response;
use Leads\Core\Router;
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
        $router->post('/api/estudios', $c(CatalogController::class, 'createStudy'), Router::ADMIN);
        $router->put('/api/estudios/{id}', $c(CatalogController::class, 'updateStudy'), Router::ADMIN);
        $router->delete('/api/estudios/{id}', $c(CatalogController::class, 'deleteStudy'), Router::ADMIN);
        $router->post('/api/estudios/{id}/alias', $c(CatalogController::class, 'addAlias'), Router::ADMIN);
        $router->delete('/api/alias/{id}', $c(CatalogController::class, 'deleteAlias'), Router::ADMIN);
        $router->post('/api/centros', $c(CatalogController::class, 'createCenter'), Router::ADMIN);
        $router->put('/api/centros/{id}', $c(CatalogController::class, 'updateCenter'), Router::ADMIN);

        // Correos
        $router->get('/api/plantillas', $c(MailController::class, 'templates'), Router::ADMIN);
        $router->put('/api/plantillas', $c(MailController::class, 'saveTemplate'), Router::ADMIN);
        $router->delete('/api/plantillas/{estudioId}', $c(MailController::class, 'deleteTemplate'), Router::ADMIN);
        $router->post('/api/plantillas/{id}/adjunto', $c(MailController::class, 'attach'), Router::ADMIN);
        $router->delete('/api/plantillas/{id}/adjunto', $c(MailController::class, 'detach'), Router::ADMIN);
        $router->get('/api/adjuntos/{id}', $c(MailController::class, 'attachment'));

        // Usuarios
        $router->get('/api/usuarios', $c(AuthController::class, 'listUsers'), Router::ADMIN);
        $router->post('/api/usuarios', $c(AuthController::class, 'createUser'), Router::ADMIN);
        $router->put('/api/usuarios/{id}', $c(AuthController::class, 'updateUser'), Router::ADMIN);
        $router->get('/api/usuarios/{id}/correo', $c(MailAccountController::class, 'show'), Router::ADMIN);
        $router->put('/api/usuarios/{id}/correo', $c(MailAccountController::class, 'save'), Router::ADMIN);
        $router->delete('/api/usuarios/{id}/correo', $c(MailAccountController::class, 'delete'), Router::ADMIN);
        $router->post('/api/usuarios/{id}/correo/prueba', $c(MailAccountController::class, 'test'), Router::ADMIN);

        return $router;
    }
}
