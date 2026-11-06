<?php

declare(strict_types=1);

namespace Leads\Infrastructure\Persistence;

use DateTimeImmutable;

/**
 * Hora del último envío (tabla limite_envio). La reserva es un único UPDATE
 * condicional: si dos comerciales pulsan a la vez, solo una lo consigue.
 */
final class ThrottleRepository extends BaseRepository
{
    /** Valor de una fila libre: «nunca se ha enviado nada». */
    public const FREE = '2000-01-01 00:00:00';

    /** Reserva el turno si el último envío fue como muy tarde en $notAfter. */
    public function acquire(string $key, DateTimeImmutable $now, DateTimeImmutable $notAfter): bool
    {
        $take = fn (): bool => $this->execute(
            'UPDATE limite_envio SET ultimo = :ahora WHERE clave = :clave AND ultimo <= :limite',
            ['ahora' => DbTime::toDb($now), 'clave' => $key, 'limite' => DbTime::toDb($notAfter)]
        ) === 1;
        if ($take()) {
            return true;
        }
        if ($this->last($key) !== null) {
            return false;
        }

        // Sin fila (p. ej. una base de datos restaurada de un volcado que no la traía):
        // sin ella el UPDATE no reserva nunca y no saldría ningún correo. Se crea libre.
        try {
            $this->execute('INSERT INTO limite_envio (clave, ultimo) VALUES (:clave, :libre)', ['clave' => $key, 'libre' => self::FREE]);
        } catch (\PDOException) {
            // La ha creado a la vez otra petición: vale igual.
        }

        return $take();
    }

    /** Devuelve el turno (el envío falló y no llegó a salir nada). */
    public function release(string $key, DateTimeImmutable $taken, DateTimeImmutable $previous): void
    {
        $this->execute(
            'UPDATE limite_envio SET ultimo = :anterior WHERE clave = :clave AND ultimo = :tomado',
            ['anterior' => DbTime::toDb($previous), 'clave' => $key, 'tomado' => DbTime::toDb($taken)]
        );
    }

    public function last(string $key): ?DateTimeImmutable
    {
        return DbTime::fromDb($this->scalar('SELECT ultimo FROM limite_envio WHERE clave = :clave', ['clave' => $key]));
    }
}
