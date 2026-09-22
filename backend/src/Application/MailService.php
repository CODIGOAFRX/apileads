<?php

declare(strict_types=1);

namespace Leads\Application;

use Leads\Core\Clock;
use Leads\Core\HttpException;
use Leads\Domain\Lead\ActivityType;
use Leads\Domain\Mail\MailException;
use Leads\Domain\Mail\MailTransport;
use Leads\Domain\Mail\Message;
use Leads\Domain\Mail\TemplateRenderer;
use Leads\Domain\Shared\Email;
use Leads\Infrastructure\Persistence\ActivityRepository;
use Leads\Infrastructure\Persistence\CatalogRepository;
use Leads\Infrastructure\Persistence\MailRepository;

/**
 * Correo de información: elige la plantilla según los estudios de interés del
 * lead, lo envía y deja constancia en el historial.
 */
final class MailService
{
    /** Evita envíos duplicados por doble clic. */
    private const RESEND_GUARD = '-2 minutes';

    public function __construct(
        private readonly MailRepository $mails,
        private readonly ActivityRepository $activity,
        private readonly CatalogRepository $catalog,
        private readonly LeadService $leads,
        private readonly MailTransport $transport,
        private readonly TemplateRenderer $renderer,
        private readonly Clock $clock,
        private readonly ?string $replyTo = null,
    ) {
    }

    public function preview(int $leadId, array $viewer): array
    {
        $lead = $this->leads->findOrFail($leadId);
        $message = $this->compose($lead, $viewer);

        return [
            'para' => $message->to,
            'asunto' => $message->subject,
            'html' => $message->html,
            'ultimoEnvio' => $this->mails->lastSentAt($leadId),
        ];
    }

    /**
     * $subject y $html permiten retocar este correo concreto antes de enviarlo
     * (p. ej. «la matrícula cierra el viernes») sin cambiar la plantilla.
     */
    public function sendInfo(int $leadId, array $viewer, ?string $subject = null, ?string $html = null): array
    {
        $lead = $this->leads->findOrFail($leadId);
        if (!Email::isValid($lead['email'])) {
            throw new \InvalidArgumentException('Este lead no tiene un correo válido');
        }
        $now = $this->clock->now();
        if ($this->mails->countSentTo($leadId, $now->modify(self::RESEND_GUARD)) > 0) {
            throw HttpException::conflict('Ya se le ha enviado la información hace un momento');
        }

        $message = $this->personalize($this->compose($lead, $viewer), $subject, $html);
        $userId = (int) $viewer['id'];
        try {
            $this->transport->send($message);
        } catch (MailException $e) {
            $this->mails->logDelivery($leadId, $userId, $message->to, $message->subject, $e->getMessage(), $now);
            $this->activity->log($leadId, $userId, ActivityType::CORREO, 'Error al enviar la información', ['error' => $e->getMessage()], $now);
            throw new HttpException('No se pudo enviar el correo: ' . $e->getMessage(), 502);
        }

        $this->mails->logDelivery($leadId, $userId, $message->to, $message->subject, null, $now);
        $personalized = $html !== null && trim($html) !== '';
        $this->activity->log($leadId, $userId, ActivityType::CORREO, 'Información enviada' . ($personalized ? ' (retocada)' : '') . ': ' . $message->subject, ['para' => $message->to], $now);

        return $this->leads->get($leadId, $viewer);
    }

    public function templates(): array
    {
        return [
            'plantillas' => $this->mails->templates(),
            'variables' => TemplateRenderer::VARIABLES,
        ];
    }

    /** $key identifica una plantilla especial ("sin-estudio"); entonces no lleva estudio. */
    public function saveTemplate(?int $studyId, string $subject, string $body, ?string $key = null): void
    {
        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '') {
            throw new \InvalidArgumentException('La plantilla necesita asunto y contenido');
        }
        if ($key !== null && ($studyId !== null || !in_array($key, MailRepository::KEYS, true))) {
            throw new \InvalidArgumentException('Tipo de plantilla no válido');
        }
        if ($studyId !== null && $this->catalog->study($studyId) === null) {
            throw HttpException::notFound('Estudio no encontrado');
        }
        $this->mails->saveTemplate($studyId, $key, mb_substr($subject, 0, 190), self::sanitize($body), $this->clock->now());
    }

    public function deleteTemplate(int $studyId): void
    {
        $this->mails->deleteTemplate($studyId);
    }

    private function compose(array $lead, array $viewer): Message
    {
        $studies = $lead['estudios'] ?? [];
        $names = array_column($studies, 'nombre');
        $variables = [
            'nombre' => $lead['nombre'],
            'nombre_completo' => trim($lead['nombre'] . ' ' . $lead['apellidos']),
            'estudios' => $names === [] ? 'nuestros estudios' : TemplateRenderer::humanList($names),
            'comercial' => (string) ($viewer['nombre'] ?? 'Equipo de admisiones'),
        ];
        $variables['estudio'] = $variables['estudios'];

        // Sin estudio: correo propio. Uno: el del estudio. Varios (o sin plantilla): la genérica con sus bloques.
        $specific = match (count($studies)) {
            0 => $this->mails->templateByKey(MailRepository::NO_STUDY),
            1 => $this->mails->templateFor((int) $studies[0]['id']),
            default => null,
        };
        if ($specific !== null) {
            $template = $specific;
            $variables['bloques_estudios'] = '';
        } else {
            $template = $this->mails->templateFor(null)
                ?? throw new \InvalidArgumentException('Falta la plantilla de correo genérica. Créala en Ajustes → Correos.');
            $variables['bloques_estudios'] = $this->studyBlocks($studies, $variables);
        }

        if ($lead['nombre'] === '') {
            // "Hola {{nombre}}:" queda como "Hola:" cuando no sabemos el nombre.
            $template['cuerpo_html'] = (string) preg_replace('/\s*\{\{\s*nombre\s*\}\}/', '', $template['cuerpo_html']);
            $template['asunto'] = (string) preg_replace('/\s*\{\{\s*nombre\s*\}\}/', '', $template['asunto']);
        }

        return new Message(
            to: (string) $lead['email'],
            toName: $variables['nombre_completo'],
            subject: $this->renderer->subject($template['asunto'], $variables),
            html: $this->renderer->render($template['cuerpo_html'], $variables, true),
            replyTo: $this->replyTo ?? ($viewer['email'] ?? null),
        );
    }

    /** Aplica los retoques de la comercial, con la misma limpieza que las plantillas. */
    private function personalize(Message $message, ?string $subject, ?string $html): Message
    {
        $subject = trim((string) preg_replace('/[\r\n]+/', ' ', (string) $subject));
        $html = self::sanitize(trim((string) $html));
        if ($html !== '' && trim(strip_tags($html)) === '') {
            throw new \InvalidArgumentException('El correo se ha quedado sin texto');
        }

        return new Message(
            to: $message->to,
            toName: $message->toName,
            subject: $subject === '' ? $message->subject : mb_substr($subject, 0, 190),
            html: $html === '' ? $message->html : $html,
            replyTo: $message->replyTo,
        );
    }

    private function studyBlocks(array $studies, array $variables): string
    {
        $blocks = '';
        foreach ($studies as $study) {
            $template = $this->mails->templateFor((int) $study['id']);
            if ($template === null) {
                continue;
            }
            $vars = ['estudio' => $study['nombre'], 'estudios' => $study['nombre'], 'bloques_estudios' => ''] + $variables;
            $blocks .= '<h3 style="margin:24px 0 8px">' . htmlspecialchars($study['nombre'], ENT_QUOTES, 'UTF-8') . '</h3>'
                . $this->renderer->render($template['cuerpo_html'], $vars, true);
        }

        return $blocks;
    }

    /** Quita scripts, iframes y manejadores de eventos del HTML de las plantillas. */
    public static function sanitize(string $html): string
    {
        $html = (string) preg_replace('#<\s*(script|style|iframe|object|embed|form)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = (string) preg_replace('#<\s*(script|iframe|object|embed|form|input|meta|link)\b[^>]*>#i', '', $html);
        $html = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);

        return (string) preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:#i', '$1=$2#', $html);
    }
}
