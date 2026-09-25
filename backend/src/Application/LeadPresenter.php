<?php

declare(strict_types=1);

namespace Leads\Application;

use Leads\Core\Input;
use Leads\Domain\Lead\Inbox;
use Leads\Domain\Lead\LeadActionPolicy;
use Leads\Domain\Lead\LeadOrigin;
use Leads\Domain\Lead\LeadStatus;
use Leads\Domain\Shared\Email;
use Leads\Domain\Shared\PhoneNumber;
use Leads\Infrastructure\Persistence\DbTime;

/** Convierte una fila de base de datos en la representación de la API. */
final class LeadPresenter
{
    public function __construct(
        private readonly LeadActionPolicy $policy,
        private readonly string $whatsappTemplate,
    ) {
    }

    public function present(array $row, \DateTimeImmutable $now, array $viewer): array
    {
        $status = LeadStatus::from($row['estado']);
        $phone = PhoneNumber::tryParse($row['telefono_digitos'] ? '+' . $row['telefono_digitos'] : null);
        $emailValid = Email::isValid($row['email']);
        $attempts = (int) $row['intentos_sin_respuesta'];
        $nextCall = DbTime::fromDb($row['proxima_llamada']);
        $scheduledCall = DbTime::fromDb($row['llamada_agendada']);
        $origin = LeadOrigin::tryFrom((string) $row['origen']) ?? LeadOrigin::OTRO;
        $studies = $row['estudios'] ?? [];
        $fullName = trim($row['nombre'] . ' ' . $row['apellidos']);

        $lockUntil = DbTime::fromDb($row['bloqueado_hasta']);
        $lock = null;
        if ($row['bloqueado_por'] !== null && $lockUntil !== null && $lockUntil > $now) {
            $lock = [
                'usuario' => $row['bloqueado_por_nombre'] ?? null,
                'esMio' => (int) $row['bloqueado_por'] === (int) $viewer['id'],
                'hasta' => DbTime::toApi($row['bloqueado_hasta']),
            ];
        }

        return [
            'id' => (int) $row['id'],
            'nombre' => $row['nombre'],
            'apellidos' => $row['apellidos'],
            'nombreCompleto' => $fullName,
            'email' => $row['email'],
            'emailValido' => $emailValid,
            'telefono' => $row['telefono'],
            'telefonoValido' => $phone !== null,
            'whatsappUrl' => $phone?->whatsappUrl($this->whatsappMessage($row, $studies, $viewer)),
            'telUrl' => $phone ? 'tel:+' . $phone->digits() : null,
            'ubicacion' => $row['ubicacion'],
            'interes' => $row['interes_texto'],
            'estudios' => $studies,
            'origen' => $origin->value,
            'origenLabel' => $origin->label(),
            'campana' => $row['campana'],
            'comentarios' => $row['comentarios'],
            'extra' => $row['datos_extra'] ? (json_decode($row['datos_extra'], true) ?: []) : [],
            'estado' => $status->value,
            'estadoLabel' => $status->label(),
            'bandeja' => Inbox::of($status, $nextCall, $now, $scheduledCall)->value,
            'intentos' => $attempts,
            'proximaLlamada' => DbTime::toApi($row['proxima_llamada']),
            'ultimaLlamada' => DbTime::toApi($row['ultima_llamada']),
            'llamadaAgendada' => DbTime::toApi($row['llamada_agendada']),
            // Ha llegado la hora de la llamada agendada: sale la primera y en verde.
            'destacado' => $scheduledCall !== null && $scheduledCall <= $now && $status->isOpen(),
            'citaEn' => DbTime::toApi($row['cita_en']),
            'motivoDescarte' => $row['motivo_descarte'],
            'centro' => $row['centro_id'] ? ['id' => (int) $row['centro_id'], 'nombre' => $row['centro_nombre'] ?? null] : null,
            'bloqueo' => $lock,
            'fechaOrigen' => DbTime::toApi($row['fecha_origen']),
            'entrada' => DbTime::toApi($row['ultima_entrada']),
            'creado' => DbTime::toApi($row['creado_en']),
            'acciones' => $this->policy->availableActions($status, $phone !== null, $emailValid),
        ];
    }

    private function whatsappMessage(array $row, array $studies, array $viewer): string
    {
        $studyNames = implode(', ', array_column($studies, 'nombre'));

        return strtr($this->whatsappTemplate, [
            '{nombre}' => $row['nombre'] !== '' ? $row['nombre'] : '',
            '{comercial}' => strtok(Input::text($viewer, 'nombre'), ' ') ?: '',
            '{estudio}' => $studyNames !== '' ? $studyNames : 'nuestros estudios',
        ]);
    }
}
