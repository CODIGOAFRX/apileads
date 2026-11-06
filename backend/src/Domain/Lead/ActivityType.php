<?php

declare(strict_types=1);

namespace Leads\Domain\Lead;

/** Tipos de entrada del historial de un lead. */
final class ActivityType
{
    public const CREADO = 'CREADO';
    public const IMPORTADO = 'IMPORTADO';
    public const FUSIONADO = 'FUSIONADO';
    public const EDITADO = 'EDITADO';
    public const LLAMADA = 'LLAMADA';
    public const CAMBIO_ESTADO = 'CAMBIO_ESTADO';
    public const NOTA = 'NOTA';
    public const CORREO = 'CORREO';
    public const WHATSAPP = 'WHATSAPP';

    private function __construct()
    {
    }
}
