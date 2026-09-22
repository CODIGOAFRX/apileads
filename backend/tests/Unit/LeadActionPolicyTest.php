<?php

declare(strict_types=1);

namespace Leads\Tests\Unit;

use Leads\Domain\Lead\LeadActionPolicy as P;
use Leads\Domain\Lead\LeadStatus;
use PHPUnit\Framework\TestCase;

final class LeadActionPolicyTest extends TestCase
{
    private P $policy;

    protected function setUp(): void
    {
        $this->policy = new P();
    }

    public function testWhatsappYCorreoEstanDesdeElPrimerMomento(): void
    {
        $all = [P::LLAMAR, P::WHATSAPP, P::ENVIAR_INFO, P::INTERESADO, P::DESCARTAR];
        self::assertSame($all, $this->policy->availableActions(LeadStatus::NUEVO, true, true));
        self::assertSame($all, $this->policy->availableActions(LeadStatus::NO_CONTESTA, true, true));
        self::assertSame($all, $this->policy->availableActions(LeadStatus::AGENDADO, true, true));
    }

    public function testConTelefonoPeroSinCorreoNoSeOfreceElCorreo(): void
    {
        self::assertSame([P::LLAMAR, P::WHATSAPP, P::INTERESADO, P::DESCARTAR], $this->policy->availableActions(LeadStatus::NUEVO, true, false));
    }

    public function testSinTelefonoNiLlamadaNiWhatsapp(): void
    {
        self::assertSame([P::ENVIAR_INFO, P::INTERESADO, P::DESCARTAR], $this->policy->availableActions(LeadStatus::NUEVO, false, true));
        self::assertSame([P::INTERESADO, P::DESCARTAR], $this->policy->availableActions(LeadStatus::NUEVO, false, false));
    }

    public function testInteresadoPuedeAvanzarPeroNoVolverAMarcarseInteresado(): void
    {
        self::assertSame(
            [P::LLAMAR, P::WHATSAPP, P::ENVIAR_INFO, P::CITAR, P::MATRICULAR, P::DESCARTAR],
            $this->policy->availableActions(LeadStatus::INTERESADO, true, true)
        );
        self::assertNotContains(P::INTERESADO, $this->policy->availableActions(LeadStatus::CITA, true, true));
    }

    public function testLosCerradosSoloSePuedenReabrir(): void
    {
        self::assertSame([P::REABRIR], $this->policy->availableActions(LeadStatus::DESCARTADO, true, true));
        self::assertSame([P::REABRIR], $this->policy->availableActions(LeadStatus::MATRICULADO, true, true));
    }
}
