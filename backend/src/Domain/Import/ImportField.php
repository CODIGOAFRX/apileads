<?php

declare(strict_types=1);

namespace Leads\Domain\Import;

/** Campo del lead al que se asigna cada columna del fichero. */
enum ImportField: string
{
    case NOMBRE = 'nombre';
    case APELLIDOS = 'apellidos';
    case NOMBRE_COMPLETO = 'nombreCompleto';
    case EMAIL = 'email';
    case TELEFONO = 'telefono';
    case UBICACION = 'ubicacion';
    case ESTUDIO = 'estudio';
    case ORIGEN = 'origen';
    case CAMPANA = 'campana';
    case FECHA = 'fecha';
    case COMENTARIOS = 'comentarios';
    /** Se guarda en "datos extra" del lead para no perder nada. */
    case EXTRA = 'extra';
    case IGNORAR = 'ignorar';

    public function label(): string
    {
        return match ($this) {
            self::NOMBRE => 'Nombre',
            self::APELLIDOS => 'Apellidos',
            self::NOMBRE_COMPLETO => 'Nombre y apellidos',
            self::EMAIL => 'Correo',
            self::TELEFONO => 'Teléfono',
            self::UBICACION => 'Ubicación',
            self::ESTUDIO => 'Estudio de interés',
            self::ORIGEN => 'Origen',
            self::CAMPANA => 'Campaña / formulario',
            self::FECHA => 'Fecha de alta',
            self::COMENTARIOS => 'Comentarios',
            self::EXTRA => 'Guardar como dato extra',
            self::IGNORAR => 'Ignorar',
        };
    }

    /** Campos que solo admiten una columna; el resto se concatenan. */
    public function isSingle(): bool
    {
        return in_array($this, [
            self::NOMBRE, self::NOMBRE_COMPLETO, self::EMAIL, self::TELEFONO,
            self::ORIGEN, self::CAMPANA, self::FECHA,
        ], true);
    }
}
