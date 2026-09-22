<?php

declare(strict_types=1);

namespace Leads\Http\Controller;

use Leads\Core\Request;
use Leads\Core\Response;

final class ImportController extends Controller
{
    public function preview(Request $request): Response
    {
        [$table] = $this->uploadedTable($request);

        return Response::json($this->app->imports()->preview($table, $this->jsonField($request, 'opciones')));
    }

    public function import(Request $request): Response
    {
        [$table, $name] = $this->uploadedTable($request);

        return Response::json($this->app->imports()->import($table, $name, $this->jsonField($request, 'opciones'), $request->userId()), 201);
    }

    public function template(Request $request): Response
    {
        if ($request->query('formato') === 'csv') {
            return Response::download($this->app->imports()->templateCsv(), 'plantilla-leads.csv', 'text/csv; charset=utf-8');
        }

        return Response::download(
            $this->app->imports()->templateXlsx(),
            'plantilla-leads.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    public function history(Request $request): Response
    {
        return Response::json($this->app->imports()->history());
    }
}
