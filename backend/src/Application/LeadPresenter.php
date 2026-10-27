<?php

declare(strict_types=1);

namespace Leads\Application;

use Leads\Core\Input;
use Leads\Domain\Lead\Company;
use Leads\Domain\Lead\Inbox;
use Leads\Domain\Lead\LeadActionPolicy;
use Leads\Domain\Lead\LeadOrigin;
use Leads\Domain\Lead\LeadStatus;
use Leads\Domain\Mail\TemplateRenderer;
use Leads\Domain\Shared\Email;
use Leads\Domain\Shared\PhoneNumber;
use Leads\Infrastructure\Persistence\DbTime;

/** Convierte una fila de base de datos en la representación de la API. */
final class LeadPresenter
{
    /**
     * WhatsApp tras no contestar, como lo escribe Verónica. Variables: {saludo} (buenos
     * días/buenas tardes según la hora), {nombre}, {comercial}, {empresa}, {sobre_estudio}
     * («nuestro: MÁSTER…»), {origen_frase} («a través de la página TUMASTER») y {estudio}.
     */
    public const WHATSAPP_TEMPLATE = "{saludo}, {nombre}:\n"
        . 'Soy {comercial}, de {empresa}. Hemos recibido tu solicitud de información sobre {sobre_estudio}{origen_frase}.' . "\n"
        . 'He intentado contactar contigo por teléfono para explicarte detalles sobre la forma de acceso, metodología, precios '
        . 'y otros aspectos relacionados con la formación. ¿En qué horario te puedo llamar?';
    /** El texto que venía por defecto en WHATSAPP_MENSAJE: si el .env aún lo tiene, se usa el nuevo. */
    private const OLD_DEFAULT = 'Hola {nombre}, soy {comercial} de Estación Diseño. Te he intentado llamar por tu interés en {estudio}. ¿Cuándo te viene bien que hablemos?';

    private readonly string $whatsappTemplate;

    public function __construct(
        private readonly LeadActionPolicy $policy,
        ?string $whatsappTemplate = null,
        private readonly string $timezone = 'Europe/Madrid',
    ) {
        $custom = trim((string) $whatsappTemplate);
        $this->whatsappTemplate = $custom === '' || $custom === self::OLD_DEFAULT ? self::WHATSAPP_TEMPLATE : $custom;
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
        $company = Company::ofStudies($studies);
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
            'whatsappUrl' => $phone?->whatsappUrl($this->whatsappMessage($row, $studies, $viewer, $now)),
            'telUrl' => $phone ? 'tel:+' . $phone->digits() : null,
            'ubicacion' => $row['ubicacion'],
            'interes' => $row['interes_texto'],
            'estudios' => $studies,
            'empresa' => $company->value,
            'empresaLabel' => $company->label(),
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

    private function whatsappMessage(array $row, array $studies, array $viewer, \DateTimeImmutable $now): string
    {
        $names = array_column($studies, 'nombre');
        $upper = array_map(static fn (string $n): string => mb_strtoupper($n), $names);
        $template = $this->whatsappTemplate;
        if ($row['nombre'] === '') {
            // «Buenos días, {nombre}:» queda «Buenos días:» cuando no sabemos el nombre.
            $template = (string) preg_replace('/,?\s*\{nombre\}/u', '', $template);
        }

        return strtr($template, [
            '{saludo}' => self::greeting((int) $now->setTimezone(new \DateTimeZone($this->timezone))->format('G')),
            '{nombre}' => $row['nombre'],
            '{comercial}' => strtok(Input::text($viewer, 'nombre'), ' ') ?: '',
            '{empresa}' => Company::ofStudies($studies) === Company::ESTACION
                ? 'ESTACIÓN DISEÑO, Escuela Superior de Diseño y Artes Visuales de Granada'
                : 'GRUPO EFP',
            '{sobre_estudio}' => match (count($names)) {
                0 => 'nuestros estudios',
                1 => 'nuestro: ' . $upper[0],
                default => 'nuestros estudios: ' . TemplateRenderer::humanList($upper),
            },
            '{origen_frase}' => self::originPhrase(LeadOrigin::tryFrom((string) $row['origen']) ?? LeadOrigin::OTRO),
            '{estudio}' => $names !== [] ? implode(', ', $names) : 'nuestros estudios',
        ]);
    }

    private static function greeting(int $hour): string
    {
        return match (true) {
            $hour >= 6 && $hour < 14 => 'Buenos días',
            $hour >= 14 && $hour < 21 => 'Buenas tardes',
            default => 'Buenas noches',
        };
    }

    /** Por dónde nos pidió la información, como lo diría la comercial; vacío si no lo sabemos. */
    private static function originPhrase(LeadOrigin $origin): string
    {
        return match ($origin) {
            LeadOrigin::EMAGISTER, LeadOrigin::TUMASTER, LeadOrigin::APRENDEMAS, LeadOrigin::EDUCAWEB
                => ' a través de la página ' . mb_strtoupper($origin->label()),
            LeadOrigin::WEB => ' a través de nuestra página web',
            LeadOrigin::REDES_SOCIALES => ' a través de nuestras redes sociales',
            LeadOrigin::FERIA => ' en la feria',
            LeadOrigin::COLEGIOS => ' a través de tu centro educativo',
            default => '',
        };
    }
}
