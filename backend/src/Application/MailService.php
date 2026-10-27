<?php

declare(strict_types=1);

namespace Leads\Application;

use DateTimeImmutable;
use Leads\Core\Clock;
use Leads\Core\HttpException;
use Leads\Core\Input;
use Leads\Domain\Lead\ActivityType;
use Leads\Domain\Mail\Attachment;
use Leads\Domain\Mail\MailException;
use Leads\Domain\Mail\MailTransport;
use Leads\Domain\Mail\Message;
use Leads\Domain\Mail\TemplateRenderer;
use Leads\Domain\Shared\Email;
use Leads\Infrastructure\Mail\PersonalMailbox;
use Leads\Infrastructure\Persistence\ActivityRepository;
use Leads\Infrastructure\Persistence\CatalogRepository;
use Leads\Infrastructure\Persistence\DbTime;
use Leads\Infrastructure\Persistence\MailRepository;
use Leads\Infrastructure\Persistence\ThrottleRepository;

/**
 * Correo de información: elige la plantilla según los estudios de interés del
 * lead, le adjunta el dossier, lo envía desde el buzón de la comercial (o el
 * general si no tiene) y deja constancia en el historial.
 */
final class MailService
{
    /** Evita envíos duplicados por doble clic. */
    private const RESEND_GUARD = '-2 minutes';

    /** Turno de envío compartido por toda la aplicación (ver ThrottleRepository). */
    private const THROTTLE_KEY = 'correo';

    /** Parte de la plantilla de un estudio que se copia en el correo de varios estudios. */
    private const BLOCK = '/\{\{\s*inicio_bloque\s*\}\}(.*?)\{\{\s*fin_bloque\s*\}\}/s';

    public const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;
    private const ATTACHMENT_TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    /**
     * $personalDelivery = false: aunque la comercial tenga buzón, se envía por
     * $transport (solo en las pruebas).
     * $simulated = true: $transport no envía de verdad (MAIL_TRANSPORT=log); se
     * dice claramente en lugar de darlo por enviado.
     * $secondsBetween: como mucho un correo cada tantos segundos en toda la
     * aplicación, para no pasar del límite del servidor (50 por hora) ni acabar
     * como spam. Sin cola: si no toca, se avisa de cuánto falta.
     */
    public function __construct(
        private readonly MailRepository $mails,
        private readonly ActivityRepository $activity,
        private readonly CatalogRepository $catalog,
        private readonly LeadService $leads,
        private readonly MailTransport $transport,
        private readonly TemplateRenderer $renderer,
        private readonly Clock $clock,
        private readonly ?string $replyTo = null,
        private readonly ?MailAccountService $accounts = null,
        private readonly bool $personalDelivery = false,
        private readonly ?ThrottleRepository $throttle = null,
        private readonly int $secondsBetween = 72,
        private readonly bool $simulated = false,
    ) {
    }

    public function preview(int $leadId, array $viewer): array
    {
        $lead = $this->leads->findOrFail($leadId, $viewer);
        [$message, $attachmentIds] = $this->compose($lead, $viewer);
        $personal = $this->personalDelivery && $message->from !== null;

        return [
            'de' => $message->from,
            'para' => $message->to,
            'asunto' => $message->subject,
            'html' => $message->html,
            // Va debajo del texto y no se retoca al enviar (Ajustes → Usuarios).
            'firma' => self::signature($viewer),
            'adjuntos' => $this->attachmentList($attachmentIds),
            'ultimoEnvio' => $this->mails->lastSentAt($leadId),
            'esperaSegundos' => $this->secondsToWait(),
            'intervaloSegundos' => $this->secondsBetween,
            'simulado' => $this->simulated && !$personal,
        ];
    }

    /** Segundos que faltan para poder enviar otro correo (0 = ya se puede). */
    public function secondsToWait(): int
    {
        $last = $this->throttle?->last(self::THROTTLE_KEY);
        if ($last === null || $this->secondsBetween <= 0) {
            return 0;
        }

        return max(0, $this->secondsBetween - ($this->clock->now()->getTimestamp() - $last->getTimestamp()));
    }

    /**
     * $subject y $html permiten retocar este correo concreto antes de enviarlo
     * (p. ej. «la matrícula cierra el viernes») sin cambiar la plantilla.
     */
    public function sendInfo(int $leadId, array $viewer, ?string $subject = null, ?string $html = null): array
    {
        $lead = $this->leads->findOrFail($leadId, $viewer);
        if (!Email::isValid($lead['email'])) {
            throw new \InvalidArgumentException('Este lead no tiene un correo válido');
        }
        $now = $this->clock->now();
        if ($this->mails->countSentTo($leadId, $now->modify(self::RESEND_GUARD)) > 0) {
            throw HttpException::conflict('Ya se le ha enviado la información hace un momento');
        }

        [$message, $attachmentIds] = $this->compose($lead, $viewer);
        $message = $this->personalize($message, $subject, $html);
        $message = $message->with([
            'html' => $message->html . (self::signature($viewer) ?? ''),
            'attachments' => $this->attachments($attachmentIds),
        ]);
        $userId = (int) $viewer['id'];
        $transport = $this->transportFor($userId);
        $simulated = $this->simulated && !$transport instanceof PersonalMailbox;

        $previous = $this->takeTurn($now);
        try {
            $transport->send($message);
        } catch (MailException $e) {
            if ($previous !== null) {
                $this->throttle?->release(self::THROTTLE_KEY, $now, $previous);
            }
            $this->mails->logDelivery($leadId, $userId, $message->to, $message->subject, $e->getMessage(), $now, $message->from);
            $this->activity->log($leadId, $userId, ActivityType::CORREO, 'Error al enviar la información', ['error' => $e->getMessage()], $now);
            throw new HttpException('No se pudo enviar el correo: ' . $e->getMessage(), 502);
        }

        $this->mails->logDelivery($leadId, $userId, $message->to, $message->subject, null, $now, $message->from, $simulated);
        $personalized = $html !== null && trim($html) !== '';
        $warning = $transport instanceof PersonalMailbox ? $transport->warning : null;
        $detail = [
            'para' => $message->to,
            'de' => $message->from,
            'adjuntos' => array_map(static fn (Attachment $a): string => $a->name, $message->attachments),
            'aviso' => $warning,
        ];
        $summary = $simulated
            ? 'Correo NO enviado (modo pruebas, MAIL_TRANSPORT=log): ' . $message->subject
            : 'Información enviada' . ($personalized ? ' (retocada)' : '') . ': ' . $message->subject;
        $this->activity->log($leadId, $userId, ActivityType::CORREO, $summary, array_filter($detail), $now);

        return $this->leads->get($leadId, $viewer) + ['envio' => ['simulado' => $simulated, 'aviso' => $warning]];
    }

    /**
     * Reserva el turno de envío o avisa de cuánto falta (429).
     *
     * @return ?DateTimeImmutable el envío anterior, para devolver el turno si este falla
     */
    private function takeTurn(DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($this->throttle === null || $this->secondsBetween <= 0) {
            return null;
        }
        // Sin envíos previos el turno se devuelve como libre si el envío falla.
        $previous = $this->throttle->last(self::THROTTLE_KEY) ?? DbTime::fromDb(ThrottleRepository::FREE);
        if (!$this->throttle->acquire(self::THROTTLE_KEY, $now, $now->modify("-{$this->secondsBetween} seconds"))) {
            $wait = max(1, $this->secondsToWait());
            throw new HttpException(
                "Para que no nos marquen como spam, se envía como mucho un correo cada {$this->secondsBetween} segundos. Podrás enviarlo en {$wait} s.",
                429,
                ['esperaSegundos' => $wait],
            );
        }

        return $previous;
    }

    public function templates(): array
    {
        return [
            'plantillas' => $this->mails->templates(),
            'variables' => TemplateRenderer::VARIABLES,
            'maxAdjuntoMb' => self::MAX_ATTACHMENT_BYTES / 1024 / 1024,
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

    /** Sube (o sustituye) el dossier de una plantilla. $path es el fichero temporal subido. */
    public function attachToTemplate(int $templateId, string $path, string $originalName): array
    {
        $this->mails->template($templateId) ?? throw HttpException::notFound('Plantilla no encontrada');
        $name = trim((string) preg_replace('/[\x00-\x1F"\\\\\/]+/', ' ', basename($originalName)));
        $type = self::ATTACHMENT_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
        if ($type === null) {
            throw new \InvalidArgumentException('El dossier debe ser un PDF (o una imagen JPG/PNG)');
        }
        $size = (int) @filesize($path);
        if ($size <= 0 || $size > self::MAX_ATTACHMENT_BYTES) {
            throw new \InvalidArgumentException('El fichero está vacío o supera los ' . (self::MAX_ATTACHMENT_BYTES / 1024 / 1024) . ' MB');
        }
        $content = (string) file_get_contents($path);
        if ($type === 'application/pdf' && !str_starts_with($content, '%PDF')) {
            throw new \InvalidArgumentException('El fichero no es un PDF válido');
        }
        $this->mails->attach($templateId, mb_substr($name, 0, 190), $type, $content, $this->clock->now());

        return $this->templates();
    }

    public function detachFromTemplate(int $templateId): array
    {
        $this->mails->template($templateId) ?? throw HttpException::notFound('Plantilla no encontrada');
        $this->mails->detach($templateId);

        return $this->templates();
    }

    /** @return array{nombre: string, tipo: string, contenido: string} */
    public function attachment(int $id): array
    {
        $row = $this->mails->attachment($id) ?? throw HttpException::notFound('Adjunto no encontrado');

        return ['nombre' => $row['nombre'], 'tipo' => $row['tipo'], 'contenido' => $row['contenido']];
    }

    /** @return array{0: Message, 1: list<int>} el correo (sin adjuntos) y los ids de sus adjuntos */
    private function compose(array $lead, array $viewer): array
    {
        $studies = $lead['estudios'] ?? [];
        $names = array_column($studies, 'nombre');
        $sender = Input::text($viewer, 'nombre', 'Equipo de admisiones');
        $variables = [
            'nombre' => $lead['nombre'],
            'nombre_completo' => trim($lead['nombre'] . ' ' . $lead['apellidos']),
            'estudios' => $names === [] ? 'nuestros estudios' : TemplateRenderer::humanList($names),
            'comercial' => $sender,
            'comercial_nombre' => explode(' ', trim($sender))[0],
        ];
        $variables['estudio'] = $variables['estudios'];

        // Sin estudio: correo propio. Uno: el del estudio. Varios (o sin plantilla): la genérica con sus bloques.
        $specific = match (count($studies)) {
            0 => $this->mails->templateByKey(MailRepository::NO_STUDY),
            1 => $this->mails->templateFor((int) $studies[0]['id']),
            default => null,
        };
        $attachmentIds = [];
        if ($specific !== null) {
            $template = $specific;
            $variables['bloques_estudios'] = '';
        } else {
            $template = $this->mails->templateFor(null)
                ?? throw new \InvalidArgumentException('Falta la plantilla de correo genérica. Créala en Ajustes → Correos.');
            [$variables['bloques_estudios'], $attachmentIds] = $this->studyBlocks($studies, $variables);
        }
        if ($template['adjunto_id'] !== null) {
            array_unshift($attachmentIds, (int) $template['adjunto_id']);
        }

        if ($lead['nombre'] === '') {
            // "Buenos días, {{nombre}}:" queda como "Buenos días:" cuando no sabemos el nombre.
            $template['cuerpo_html'] = (string) preg_replace('/,?\s*\{\{\s*nombre\s*\}\}/', '', $template['cuerpo_html']);
            $template['asunto'] = (string) preg_replace('/,?\s*\{\{\s*nombre\s*\}\}/', '', $template['asunto']);
        }

        $from = $this->accounts?->senderAddress((int) $viewer['id']);
        $message = new Message(
            to: (string) $lead['email'],
            toName: $variables['nombre_completo'],
            subject: $this->renderer->subject($template['asunto'], $variables),
            html: $this->renderer->render($template['cuerpo_html'], $variables, true),
            // Desde su propio buzón, las respuestas ya le llegan a ella.
            replyTo: $from !== null ? null : ($this->replyTo ?? ($viewer['email'] ?? null)),
            from: $from,
            fromName: $from !== null ? $sender : '',
        );

        return [$message, array_values(array_unique($attachmentIds))];
    }

    /** Aplica los retoques de la comercial, con la misma limpieza que las plantillas. */
    private function personalize(Message $message, ?string $subject, ?string $html): Message
    {
        $subject = trim((string) preg_replace('/[\r\n]+/', ' ', (string) $subject));
        $html = self::sanitize(trim((string) $html));
        if ($html !== '' && trim(strip_tags($html)) === '') {
            throw new \InvalidArgumentException('El correo se ha quedado sin texto');
        }

        return $message->with([
            'subject' => $subject === '' ? $message->subject : mb_substr($subject, 0, 190),
            'html' => $html === '' ? $message->html : $html,
        ]);
    }

    /**
     * Contenido de cada estudio para la genérica: lo que hay entre
     * {{inicio_bloque}} y {{fin_bloque}} o, si no están, la plantilla entera con su título.
     *
     * @return array{0: string, 1: list<int>} el HTML y los adjuntos de esos estudios
     */
    private function studyBlocks(array $studies, array $variables): array
    {
        $blocks = '';
        $attachmentIds = [];
        foreach ($studies as $study) {
            $template = $this->mails->templateFor((int) $study['id']);
            if ($template === null) {
                continue;
            }
            $vars = ['estudio' => $study['nombre'], 'estudios' => $study['nombre'], 'bloques_estudios' => ''] + $variables;
            if (preg_match(self::BLOCK, $template['cuerpo_html'], $m)) {
                $blocks .= $this->renderer->render(trim($m[1]), $vars, true);
            } else {
                $blocks .= '<h3 style="margin:24px 0 8px">' . htmlspecialchars($study['nombre'], ENT_QUOTES, 'UTF-8') . '</h3>'
                    . $this->renderer->render($template['cuerpo_html'], $vars, true);
            }
            if ($template['adjunto_id'] !== null) {
                $attachmentIds[] = (int) $template['adjunto_id'];
            }
        }

        return [$blocks, $attachmentIds];
    }

    private function transportFor(int $userId): MailTransport
    {
        $mailbox = $this->personalDelivery ? $this->accounts?->mailbox($userId) : null;

        return $mailbox ?? $this->transport;
    }

    /** @param list<int> $ids */
    private function attachmentList(array $ids): array
    {
        $list = [];
        foreach ($ids as $id) {
            $info = $this->mails->attachmentInfo($id);
            if ($info !== null) {
                $list[] = ['id' => (int) $info['id'], 'nombre' => $info['nombre'], 'tamano' => (int) $info['tamano']];
            }
        }

        return $list;
    }

    /**
     * @param list<int> $ids
     * @return list<Attachment>
     */
    private function attachments(array $ids): array
    {
        $list = [];
        foreach ($ids as $id) {
            $row = $this->mails->attachment($id);
            if ($row !== null) {
                $list[] = new Attachment($row['nombre'], $row['tipo'], $row['contenido']);
            }
        }

        return $list;
    }

    /** Quita scripts, iframes y manejadores de eventos del HTML de las plantillas. */
    /** Firma de quien envía, ya lista para ir al final del correo; null si no tiene. */
    private static function signature(array $viewer): ?string
    {
        $html = trim(Input::text($viewer, 'firma'));

        return $html === '' ? null : '<div class="firma" style="margin-top:28px">' . self::sanitize($html) . '</div>';
    }

    public static function sanitize(string $html): string
    {
        $html = (string) preg_replace('#<\s*(script|style|iframe|object|embed|form)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = (string) preg_replace('#<\s*(script|iframe|object|embed|form|input|meta|link)\b[^>]*>#i', '', $html);
        $html = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);

        return (string) preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:#i', '$1=$2#', $html);
    }
}
