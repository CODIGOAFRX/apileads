<?php

declare(strict_types=1);

namespace Leads\Http\Controller;

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
            is_string($subject) ? $subject : null,
            is_string($html) ? $html : null,
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
            (string) $request->input('asunto', ''),
            (string) $request->input('cuerpoHtml', ''),
            ($request->input('clave') ?? '') === '' ? null : (string) $request->input('clave'),
        );

        return Response::json($this->app->mail()->templates());
    }

    public function deleteTemplate(Request $request): Response
    {
        $this->app->mail()->deleteTemplate($request->intParam('estudioId'));

        return Response::noContent();
    }
}
