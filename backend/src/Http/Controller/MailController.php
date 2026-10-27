<?php

declare(strict_types=1);

namespace Leads\Http\Controller;

use Leads\Core\HttpException;
use Leads\Core\Input;
use Leads\Core\Request;
use Leads\Core\Response;

final class MailController extends Controller
{
    public function preview(Request $request): Response
    {
        return Response::json($this->app->mail()->preview($request->intParam('id'), $request->user()));
    }

    public function send(Request $request): Response
    {
        $subject = $request->input('asunto');
        $html = $request->input('html');

        return Response::json($this->app->mail()->sendInfo(
            $request->intParam('id'),
            $request->user(),
            is_string($subject) ? Input::toText($subject) : null,
            is_string($html) ? Input::toText($html) : null,
        ));
    }

    public function templates(Request $request): Response
    {
        return Response::json($this->app->mail()->templates());
    }

    public function saveTemplate(Request $request): Response
    {
        $this->app->mail()->saveTemplate(
            $this->optionalId($request->input('estudioId')),
            $request->text('asunto'),
            $request->text('cuerpoHtml'),
            $request->optionalText('clave') ?: null,
        );

        return Response::json($this->app->mail()->templates());
    }

    public function deleteTemplate(Request $request): Response
    {
        $this->app->mail()->deleteTemplate($request->intParam('estudioId'));

        return Response::noContent();
    }

    /** Dossier de la plantilla: fichero en el campo "archivo". */
    public function attach(Request $request): Response
    {
        $file = $request->file('archivo') ?? throw HttpException::badRequest('No se ha recibido ningún fichero');
        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw HttpException::badRequest('El fichero es demasiado grande para el servidor');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest('No se pudo subir el fichero (código ' . $error . ')');
        }

        return Response::json($this->app->mail()->attachToTemplate(
            $request->intParam('id'),
            (string) $file['tmp_name'],
            Input::text($file, 'name', 'dossier.pdf'),
        ));
    }

    public function detach(Request $request): Response
    {
        return Response::json($this->app->mail()->detachFromTemplate($request->intParam('id')));
    }

    public function attachment(Request $request): Response
    {
        $file = $this->app->mail()->attachment($request->intParam('id'));

        return Response::download($file['contenido'], $file['nombre'], $file['tipo']);
    }
}
