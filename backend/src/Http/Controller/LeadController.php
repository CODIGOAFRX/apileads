<?php

declare(strict_types=1);

namespace Leads\Http\Controller;

use Leads\Application\AuditService;
use Leads\Application\LeadService;
use Leads\Core\Request;
use Leads\Core\Response;

final class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        return Response::json($this->app->leads()->search($request->allQuery(), $request->user()));
    }

    public function counters(Request $request): Response
    {
        return Response::json($this->app->leads()->counters($request->user()));
    }

    public function show(Request $request): Response
    {
        $lead = $this->app->leads()->get($request->intParam('id'), $request->user());
        // ?vista=1 solo cuando la comercial abre la ficha (no en cada recarga tras una acción).
        if ($request->query('vista')) {
            $this->audit($request, AuditService::FICHA, 'Abre la ficha', [], $lead['id'], 120);
        }

        return Response::json($lead);
    }

    public function store(Request $request): Response
    {
        return Response::json($this->app->leads()->create($request->body(), $request->user()), 201);
    }

    public function update(Request $request): Response
    {
        return Response::json($this->app->leads()->update($request->intParam('id'), $request->body(), $request->user()));
    }

    public function lock(Request $request): Response
    {
        $lead = $this->app->leads()->lock($request->intParam('id'), $request->user());
        $this->audit($request, AuditService::LLAMADA_ABIERTA, 'Abre la llamada', [], $lead['id'], 60);

        return Response::json($lead);
    }

    /** Ha pulsado el número de teléfono (enlace tel:) para llamar. */
    public function dial(Request $request): Response
    {
        $lead = $this->app->leads()->findOrFail($request->intParam('id'), $request->user());
        $this->audit($request, AuditService::MARCADO, 'Pulsa el teléfono para llamar', ['telefono' => $lead['telefono']], (int) $lead['id'], 10);

        return Response::noContent();
    }

    public function unlock(Request $request): Response
    {
        $this->app->leads()->unlock($request->intParam('id'), $request->user());

        return Response::noContent();
    }

    public function call(Request $request): Response
    {
        return Response::json($this->app->leads()->registerCall(
            $request->intParam('id'),
            $request->text('resultado'),
            $request->optionalText('nota'),
            $request->user(),
        ));
    }

    public function scheduleCall(Request $request): Response
    {
        return Response::json($this->app->leads()->scheduleCall(
            $request->intParam('id'),
            $request->text('fecha'),
            $request->optionalText('nota'),
            $request->user(),
        ));
    }

    public function interested(Request $request): Response
    {
        return Response::json($this->app->leads()->markInterested($request->intParam('id'), $request->user()));
    }

    public function appointment(Request $request): Response
    {
        return Response::json($this->app->leads()->scheduleAppointment(
            $request->intParam('id'),
            $request->text('fecha'),
            $request->optionalText('nota'),
            $request->user(),
        ));
    }

    public function enroll(Request $request): Response
    {
        return Response::json($this->app->leads()->enroll($request->intParam('id'), $request->optionalText('nota'), $request->user()));
    }

    public function discard(Request $request): Response
    {
        return Response::json($this->app->leads()->discard($request->intParam('id'), $request->text('motivo'), $request->user()));
    }

    public function reopen(Request $request): Response
    {
        return Response::json($this->app->leads()->reopen($request->intParam('id'), $request->user()));
    }

    public function note(Request $request): Response
    {
        return Response::json($this->app->leads()->addNote($request->intParam('id'), $request->text('texto'), $request->user()));
    }

    public function whatsapp(Request $request): Response
    {
        return Response::json($this->app->leads()->logWhatsapp($request->intParam('id'), $request->user()));
    }

    public function export(Request $request): Response
    {
        $name = 'leads-' . ($request->queryText('bandeja') ?: 'pendientes') . '-' . date('Y-m-d') . '.csv';
        $this->audit($request, AuditService::EXPORTACION, 'Exporta leads a CSV (' . ($request->queryText('bandeja') ?: 'pendientes') . ')', ['filtros' => $request->allQuery()]);

        return Response::download($this->app->exports()->csv(LeadService::scopedQuery($request->allQuery(), $request->user())), $name, 'text/csv; charset=utf-8');
    }
}
