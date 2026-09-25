<?php

declare(strict_types=1);

namespace Leads\Http\Controller;

use Leads\Core\Request;
use Leads\Core\Response;

final class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        return Response::json($this->app->catalog()->catalog());
    }

    public function createStudy(Request $request): Response
    {
        return Response::json($this->app->catalog()->saveStudy(null, $request->body(), $request->user()), 201);
    }

    public function updateStudy(Request $request): Response
    {
        return Response::json($this->app->catalog()->saveStudy($request->intParam('id'), $request->body(), $request->user()));
    }

    public function deleteStudy(Request $request): Response
    {
        $this->app->catalog()->deleteStudy($request->intParam('id'));

        return Response::noContent();
    }

    public function addAlias(Request $request): Response
    {
        return Response::json($this->app->catalog()->addAlias(
            $request->intParam('id'),
            $request->text('alias'),
            $this->app->clock()->now(),
        ), 201);
    }

    public function deleteAlias(Request $request): Response
    {
        $this->app->catalog()->deleteAlias($request->intParam('id'));

        return Response::noContent();
    }

    public function createCenter(Request $request): Response
    {
        return Response::json($this->app->catalog()->saveCenter(null, $request->body()), 201);
    }

    public function updateCenter(Request $request): Response
    {
        return Response::json($this->app->catalog()->saveCenter($request->intParam('id'), $request->body()));
    }
}
