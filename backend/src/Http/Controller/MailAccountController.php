<?php

declare(strict_types=1);

namespace Leads\Http\Controller;

use Leads\Core\Request;
use Leads\Core\Response;

/** Buzón de correo de cada usuario (solo administración). */
final class MailAccountController extends Controller
{
    public function show(Request $request): Response
    {
        return Response::json(['cuenta' => $this->app->mailAccounts()->get($request->intParam('id'))]);
    }

    public function save(Request $request): Response
    {
        return Response::json(['cuenta' => $this->app->mailAccounts()->save($request->intParam('id'), $request->body())]);
    }

    public function delete(Request $request): Response
    {
        $this->app->mailAccounts()->delete($request->intParam('id'));

        return Response::noContent();
    }

    public function test(Request $request): Response
    {
        return Response::json($this->app->mailAccounts()->test($request->intParam('id'), $request->body()));
    }
}
