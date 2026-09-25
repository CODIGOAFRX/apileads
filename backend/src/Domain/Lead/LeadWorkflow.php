<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

use DateTimeImmutable;

/**
 * Reglas del flujo comercial. No toca la base de datos: recibe un estado y
 * devuelve el siguiente, lo que permite probar cada regla de forma aislada.
 */
final class LeadWorkflow
{
    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly WorkflowSettings $settings,
    ) {
    }

    /** Cualquier resultado de llamada cumple la llamada agendada que hubiera. */
    public function registerCall(LeadState $state, CallOutcome $outcome, DateTimeImmutable $now): Transition
    {
        $this->assertOpen($state);

        return match ($outcome) {
            CallOutcome::NO_CONTESTA => $this->noAnswer($state, $now),
            CallOutcome::NO_INTERESADO => new Transition(
                $state->with([
                    'status' => LeadStatus::DESCARTADO,
                    'lastCall' => $now,
                    'nextCall' => null,
                    'scheduledCall' => null,
                    'discardReason' => 'No interesado',
                ]),
                ActivityType::LLAMADA,
                'Contesta · no interesado → descartado',
                ['resultado' => $outcome->value],
            ),
            CallOutcome::INTERESADO => new Transition(
                $this->interested($state)->with(['lastCall' => $now]),
                ActivityType::LLAMADA,
                'Contesta · interesado',
                ['resultado' => $outcome->value],
            ),
        };
    }

    /**
     * Contesta pero pide que le llamen en otro momento. El lead espera en
     * «agendadas» y a esa hora vuelve a pendientes, el primero.
     */
    public function scheduleCall(LeadState $state, DateTimeImmutable $at, DateTimeImmutable $now): Transition
    {
        $this->assertOpen($state);
        if ($at <= $now) {
            throw new \InvalidArgumentException('Elige un día y una hora que aún no hayan pasado');
        }

        return new Transition(
            $state->with([
                // Un interesado sigue siéndolo; el resto pasa a «llamada agendada».
                'status' => $state->status->isInterested() ? $state->status : LeadStatus::AGENDADO,
                'attempts' => 0,
                'lastCall' => $now,
                'nextCall' => null,
                'scheduledCall' => $at,
            ]),
            ActivityType::LLAMADA,
            'Llamada agendada para el ' . $this->calendar->local($at)->format('d/m/Y \a \l\a\s H:i'),
            ['resultado' => 'AGENDADA', 'agendada_para' => $at->format(DATE_ATOM)],
        );
    }

    /** Se sabe que le interesa sin haberle llamado (p. ej. ha contestado por WhatsApp). */
    public function markInterested(LeadState $state): Transition
    {
        $this->assertOpen($state);
        if ($state->status->isInterested()) {
            throw new \InvalidArgumentException('El lead ya está en interesados');
        }

        return new Transition(
            $this->interested($state),
            ActivityType::CAMBIO_ESTADO,
            'Marcado como interesado',
        );
    }

    public function scheduleAppointment(LeadState $state, DateTimeImmutable $at): Transition
    {
        $this->assertOpen($state);

        return new Transition(
            $state->with(['status' => LeadStatus::CITA, 'appointmentAt' => $at, 'nextCall' => null]),
            ActivityType::CAMBIO_ESTADO,
            'Cita agendada',
            ['cita_en' => $at->format(DATE_ATOM)],
        );
    }

    public function enroll(LeadState $state): Transition
    {
        $this->assertOpen($state);

        return new Transition(
            $state->with(['status' => LeadStatus::MATRICULADO, 'nextCall' => null, 'scheduledCall' => null]),
            ActivityType::CAMBIO_ESTADO,
            'Matriculado',
        );
    }

    public function discard(LeadState $state, string $reason): Transition
    {
        $this->assertOpen($state);
        // motivo_descarte es VARCHAR(255).
        $reason = trim($reason) === '' ? 'Descartado manualmente' : mb_substr(trim($reason), 0, 255);

        return new Transition(
            $state->with([
                'status' => LeadStatus::DESCARTADO,
                'nextCall' => null,
                'scheduledCall' => null,
                'discardReason' => $reason,
            ]),
            ActivityType::CAMBIO_ESTADO,
            'Descartado: ' . $reason,
        );
    }

    public function reopen(LeadState $state): Transition
    {
        if ($state->status === LeadStatus::NUEVO) {
            throw new \InvalidArgumentException('El lead ya está pendiente');
        }

        return new Transition(
            $this->freshState($state),
            ActivityType::CAMBIO_ESTADO,
            'Reabierto desde «' . $state->status->label() . '»',
        );
    }

    /**
     * El lead vuelve a dejar sus datos (importación o alta de un duplicado).
     * Devuelve null si no hay que cambiar nada.
     */
    public function reentry(LeadState $state, DateTimeImmutable $now): ?Transition
    {
        return match ($state->status) {
            LeadStatus::DESCARTADO => new Transition(
                $this->freshState($state),
                ActivityType::CAMBIO_ESTADO,
                'Reactivado: volvió a pedir información',
            ),
            LeadStatus::NO_CONTESTA => new Transition(
                $state->with(['nextCall' => $now]),
                ActivityType::CAMBIO_ESTADO,
                'Vuelve a pendientes: volvió a pedir información',
            ),
            default => null,
        };
    }

    private function noAnswer(LeadState $state, DateTimeImmutable $now): Transition
    {
        $attempts = $state->attempts + 1;
        $detail = ['resultado' => CallOutcome::NO_CONTESTA->value, 'intento' => $attempts];

        // Con un lead interesado solo se anota el intento: no se mueve de pestaña.
        if ($state->status->isInterested()) {
            return new Transition(
                $state->with(['attempts' => $attempts, 'lastCall' => $now, 'scheduledCall' => null]),
                ActivityType::LLAMADA,
                "No contesta (intento {$attempts})",
                $detail,
            );
        }

        if ($this->settings->maxAttempts > 0 && $attempts >= $this->settings->maxAttempts) {
            return new Transition(
                $state->with([
                    'status' => LeadStatus::DESCARTADO,
                    'attempts' => $attempts,
                    'lastCall' => $now,
                    'nextCall' => null,
                    'scheduledCall' => null,
                    'discardReason' => "Sin respuesta tras {$attempts} intentos",
                ]),
                ActivityType::LLAMADA,
                "No contesta (intento {$attempts}) → descartado automáticamente",
                $detail,
            );
        }

        $nextCall = $this->calendar->nextBusinessMorning($now);

        return new Transition(
            $state->with([
                'status' => LeadStatus::NO_CONTESTA,
                'attempts' => $attempts,
                'lastCall' => $now,
                'nextCall' => $nextCall,
                'scheduledCall' => null,
            ]),
            ActivityType::LLAMADA,
            "No contesta (intento {$attempts})",
            $detail + ['proxima_llamada' => $nextCall->format(DATE_ATOM)],
        );
    }

    private function interested(LeadState $state): LeadState
    {
        return $state->with([
            // Si ya tenía cita, no se deshace.
            'status' => $state->status === LeadStatus::CITA ? LeadStatus::CITA : LeadStatus::INTERESADO,
            'attempts' => 0,
            'nextCall' => null,
            'scheduledCall' => null,
        ]);
    }

    private function freshState(LeadState $state): LeadState
    {
        return $state->with([
            'status' => LeadStatus::NUEVO,
            'attempts' => 0,
            'nextCall' => null,
            'scheduledCall' => null,
            'discardReason' => null,
        ]);
    }

    private function assertOpen(LeadState $state): void
    {
        if (!$state->status->isOpen()) {
            throw new \InvalidArgumentException(
                'El lead está «' . $state->status->label() . '». Reábrelo para seguir trabajándolo.'
            );
        }
    }
}
