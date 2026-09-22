<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use DateTimeImmutable;
use Leads\Domain\Lead\BusinessCalendar;
use Leads\Domain\Lead\CallOutcome;
use Leads\Domain\Lead\Inbox;
use Leads\Domain\Lead\LeadState;
use Leads\Domain\Lead\LeadStatus;
use Leads\Domain\Lead\LeadWorkflow;
use Leads\Domain\Lead\WorkflowSettings;
use PHPUnit\Framework\TestCase;

final class LeadWorkflowTest extends TestCase
{
    private LeadWorkflow $workflow;
    private DateTimeImmutable $tuesday;

    protected function setUp(): void
    {
        $this->workflow = new LeadWorkflow(
            new BusinessCalendar('Europe/Madrid', '09:00'),
            new WorkflowSettings(maxAttempts: 6),
        );
        $this->tuesday = new DateTimeImmutable('2026-09-15T10:00:00+02:00');
    }

    public function testNoContestaSaleDePendientesYVuelveALaMananaSiguiente(): void
    {
        $transition = $this->workflow->registerCall(new LeadState(), CallOutcome::NO_CONTESTA, $this->tuesday);
        $state = $transition->state;

        self::assertSame(LeadStatus::NO_CONTESTA, $state->status);
        self::assertSame(1, $state->attempts);
        self::assertEquals(new DateTimeImmutable('2026-09-16T09:00:00+02:00'), $state->nextCall);
        self::assertSame(Inbox::NO_CONTESTAN, Inbox::of($state->status, $state->nextCall, $this->tuesday));
        self::assertSame(Inbox::NO_CONTESTAN, Inbox::of($state->status, $state->nextCall, new DateTimeImmutable('2026-09-16T08:59:59+02:00')));
        self::assertSame(Inbox::PENDIENTES, Inbox::of($state->status, $state->nextCall, new DateTimeImmutable('2026-09-16T09:00:00+02:00')));
        self::assertSame('No contesta (intento 1)', $transition->summary);
    }

    public function testCuentaLosIntentosYDescartaAlLlegarAlMaximo(): void
    {
        $state = new LeadState();
        $now = $this->tuesday;
        for ($i = 1; $i <= 5; $i++) {
            $state = $this->workflow->registerCall($state, CallOutcome::NO_CONTESTA, $now)->state;
            self::assertSame(LeadStatus::NO_CONTESTA, $state->status);
            $now = $state->nextCall;
        }

        $state = $this->workflow->registerCall($state, CallOutcome::NO_CONTESTA, $now)->state;

        self::assertSame(LeadStatus::DESCARTADO, $state->status);
        self::assertSame(6, $state->attempts);
        self::assertNull($state->nextCall);
        self::assertSame('Sin respuesta tras 6 intentos', $state->discardReason);
    }

    public function testSinMaximoNuncaSeDescartaSolo(): void
    {
        $workflow = new LeadWorkflow(new BusinessCalendar(), new WorkflowSettings(maxAttempts: 0));
        $state = new LeadState(status: LeadStatus::NO_CONTESTA, attempts: 50);

        $state = $workflow->registerCall($state, CallOutcome::NO_CONTESTA, $this->tuesday)->state;

        self::assertSame(LeadStatus::NO_CONTESTA, $state->status);
        self::assertSame(51, $state->attempts);
    }

    public function testNoInteresadoPasaADescartados(): void
    {
        $state = $this->workflow->registerCall(new LeadState(attempts: 2, status: LeadStatus::NO_CONTESTA), CallOutcome::NO_INTERESADO, $this->tuesday)->state;

        self::assertSame(LeadStatus::DESCARTADO, $state->status);
        self::assertSame('No interesado', $state->discardReason);
        self::assertSame(Inbox::DESCARTADOS, Inbox::of($state->status, $state->nextCall, $this->tuesday));
    }

    public function testInteresadoReiniciaIntentosYNoDeshaceUnaCita(): void
    {
        $state = $this->workflow->registerCall(new LeadState(status: LeadStatus::NO_CONTESTA, attempts: 4), CallOutcome::INTERESADO, $this->tuesday)->state;
        self::assertSame(LeadStatus::INTERESADO, $state->status);
        self::assertSame(0, $state->attempts);
        self::assertNull($state->nextCall);

        $withAppointment = new LeadState(status: LeadStatus::CITA, appointmentAt: $this->tuesday);
        self::assertSame(LeadStatus::CITA, $this->workflow->registerCall($withAppointment, CallOutcome::INTERESADO, $this->tuesday)->state->status);
    }

    public function testUnInteresadoQueNoContestaSigueEnInteresados(): void
    {
        $state = $this->workflow->registerCall(new LeadState(status: LeadStatus::INTERESADO), CallOutcome::NO_CONTESTA, $this->tuesday)->state;

        self::assertSame(LeadStatus::INTERESADO, $state->status);
        self::assertSame(1, $state->attempts);
        self::assertNull($state->nextCall);
    }

    public function testNoSePuedeLlamarAUnLeadCerrado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reábrelo');

        $this->workflow->registerCall(new LeadState(status: LeadStatus::DESCARTADO), CallOutcome::INTERESADO, $this->tuesday);
    }

    public function testCitaMatriculaYReapertura(): void
    {
        $at = new DateTimeImmutable('2026-09-20T10:30:00+02:00');
        $state = $this->workflow->scheduleAppointment(new LeadState(status: LeadStatus::INTERESADO), $at)->state;
        self::assertSame(LeadStatus::CITA, $state->status);
        self::assertEquals($at, $state->appointmentAt);

        $state = $this->workflow->enroll($state)->state;
        self::assertSame(LeadStatus::MATRICULADO, $state->status);

        $state = $this->workflow->reopen($state)->state;
        self::assertSame(LeadStatus::NUEVO, $state->status);
        self::assertSame(0, $state->attempts);
    }

    public function testUnDescartadoQueVuelveAEntrarSeReactiva(): void
    {
        $discarded = new LeadState(status: LeadStatus::DESCARTADO, attempts: 6, discardReason: 'Sin respuesta');

        $state = $this->workflow->reentry($discarded, $this->tuesday)->state;

        self::assertSame(LeadStatus::NUEVO, $state->status);
        self::assertSame(0, $state->attempts);
        self::assertNull($state->discardReason);
    }

    public function testUnNoContestaQueVuelveAEntrarPasaAPendientesYa(): void
    {
        $waiting = new LeadState(status: LeadStatus::NO_CONTESTA, attempts: 2, nextCall: $this->tuesday->modify('+1 day'));

        $state = $this->workflow->reentry($waiting, $this->tuesday)->state;

        self::assertSame(Inbox::PENDIENTES, Inbox::of($state->status, $state->nextCall, $this->tuesday));
        self::assertSame(2, $state->attempts);
    }

    public function testAgendarLlamadaLoApartaHastaEsaHoraYLuegoVuelveAPendientes(): void
    {
        $at = new DateTimeImmutable('2026-09-17T17:00:00+02:00');
        $transition = $this->workflow->scheduleCall(new LeadState(status: LeadStatus::NO_CONTESTA, attempts: 2, nextCall: $this->tuesday), $at, $this->tuesday);
        $state = $transition->state;

        self::assertSame(LeadStatus::AGENDADO, $state->status);
        self::assertSame(0, $state->attempts, 'ha contestado');
        self::assertNull($state->nextCall);
        self::assertEquals($at, $state->scheduledCall);
        self::assertSame('Llamada agendada para el 17/09/2026 a las 17:00', $transition->summary);
        self::assertSame(Inbox::AGENDADAS, Inbox::of($state->status, $state->nextCall, $this->tuesday, $state->scheduledCall));
        self::assertSame(Inbox::AGENDADAS, Inbox::of($state->status, $state->nextCall, $at->modify('-1 second'), $state->scheduledCall));
        self::assertSame(Inbox::PENDIENTES, Inbox::of($state->status, $state->nextCall, $at, $state->scheduledCall));
    }

    public function testUnInteresadoConLlamadaAgendadaSigueSiendoInteresado(): void
    {
        $at = $this->tuesday->modify('+2 days');
        $state = $this->workflow->scheduleCall(new LeadState(status: LeadStatus::INTERESADO), $at, $this->tuesday)->state;

        self::assertSame(LeadStatus::INTERESADO, $state->status);
        self::assertSame(Inbox::AGENDADAS, Inbox::of($state->status, $state->nextCall, $this->tuesday, $state->scheduledCall));

        // Al llamarle, aunque no conteste, la llamada agendada queda cumplida y vuelve a interesados.
        $state = $this->workflow->registerCall($state, CallOutcome::NO_CONTESTA, $at)->state;
        self::assertNull($state->scheduledCall);
        self::assertSame(Inbox::INTERESADOS, Inbox::of($state->status, $state->nextCall, $at, $state->scheduledCall));
    }

    public function testCualquierResultadoCumpleLaLlamadaAgendada(): void
    {
        $scheduled = new LeadState(status: LeadStatus::AGENDADO, scheduledCall: $this->tuesday);
        foreach (CallOutcome::cases() as $outcome) {
            self::assertNull($this->workflow->registerCall($scheduled, $outcome, $this->tuesday)->state->scheduledCall, $outcome->value);
        }
        self::assertNull($this->workflow->discard($scheduled, 'x')->state->scheduledCall);
        self::assertNull($this->workflow->enroll($scheduled)->state->scheduledCall);

        $noAnswer = $this->workflow->registerCall($scheduled, CallOutcome::NO_CONTESTA, $this->tuesday)->state;
        self::assertSame(LeadStatus::NO_CONTESTA, $noAnswer->status);
        self::assertSame(1, $noAnswer->attempts);
    }

    public function testNoSePuedeAgendarEnElPasadoNiUnLeadCerrado(): void
    {
        try {
            $this->workflow->scheduleCall(new LeadState(), $this->tuesday, $this->tuesday);
            self::fail('Debía rechazar una hora que ya ha llegado');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('aún no hayan pasado', $e->getMessage());
        }

        $this->expectExceptionMessage('Reábrelo');
        $this->workflow->scheduleCall(new LeadState(status: LeadStatus::DESCARTADO), $this->tuesday->modify('+1 day'), $this->tuesday);
    }

    public function testMarcarInteresadoSinLlamar(): void
    {
        $scheduled = new LeadState(status: LeadStatus::AGENDADO, attempts: 3, scheduledCall: $this->tuesday->modify('+1 day'));
        $transition = $this->workflow->markInterested($scheduled);

        self::assertSame(LeadStatus::INTERESADO, $transition->state->status);
        self::assertSame(0, $transition->state->attempts);
        self::assertNull($transition->state->scheduledCall, 'pasa directamente a interesados');
        self::assertNull($transition->state->lastCall, 'no cuenta como llamada');
        self::assertSame('Marcado como interesado', $transition->summary);

        $this->expectExceptionMessage('ya está en interesados');
        $this->workflow->markInterested(new LeadState(status: LeadStatus::CITA));
    }

    public function testReentradaSinCambiosParaInteresadosYMatriculados(): void
    {
        self::assertNull($this->workflow->reentry(new LeadState(status: LeadStatus::INTERESADO), $this->tuesday));
        self::assertNull($this->workflow->reentry(new LeadState(status: LeadStatus::MATRICULADO), $this->tuesday));
        self::assertNull($this->workflow->reentry(new LeadState(), $this->tuesday));
    }
}
