<?php

declare(strict_types=1);

namespace Leads\Http\Controller;

use Leads\Core\Request;
use Leads\Core\Response;

/** Registro de actividad de las comerciales (solo administración). */
final class AuditController extends Controller
{
    public function day(Request $request): Response
    {
        return Response::json($this->app->audit()->day($request->queryText('fecha'), $this->optionalId($request->query('usuario'))));
    }

    public function export(Request $request): Response
    {
        $date = $request->queryText('fecha') ?: null;

        return Response::download(
            $this->app->audit()->csv($date, $this->optionalId($request->query('usuario'))),
            'actividad-' . ($date ?? 'hoy') . '.csv',
            'text/csv; charset=utf-8',
        );
    }
}
