<?php

declare(strict_types=1);

namespace Leads\Http\Controller;

use Leads\Core\Request;
use Leads\Core\Response;

final class AuthController extends Controller
{
    public function login(Request $request): Response
    {
        return Response::json($this->app->auth()->login(
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
            $request->ip(),
        ));
    }

    public function me(Request $request): Response
    {
        return Response::json($request->user());
    }

    public function changePassword(Request $request): Response
    {
        $this->app->auth()->changeOwnPassword(
            $request->userId(),
            (string) $request->input('actual', ''),
            (string) $request->input('nueva', ''),
        );

        return Response::noContent();
    }

    public function listUsers(Request $request): Response
    {
        return Response::json($this->app->auth()->listUsers());
    }

    public function createUser(Request $request): Response
    {
        return Response::json($this->app->auth()->createUser($request->body()), 201);
    }

    public function updateUser(Request $request): Response
    {
        return Response::json($this->app->auth()->updateUser($request->intParam('id'), $request->body(), $request->userId()));
    }
}
