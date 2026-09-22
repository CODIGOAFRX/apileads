<?php

declare(strict_types=1);

namespace Leads\Application;

use DateTimeImmutable;
use DateTimeZone;
use Leads\Core\Clock;
use Leads\Core\HttpException;
use Leads\Domain\Lead\ActivityType;
use Leads\Domain\Lead\CallOutcome;
use Leads\Domain\Lead\LeadInput;
use Leads\Domain\Lead\LeadOrigin;
use Leads\Domain\Lead\LeadSearch;
use Leads\Domain\Lead\LeadState;
use Leads\Domain\Lead\LeadStatus;
use Leads\Domain\Lead\LeadWorkflow;
use Leads\Domain\Lead\Transition;
use Leads\Domain\Lead\WorkflowSettings;
use Leads\Domain\Shared\Text;
use Leads\Infrastructure\Persistence\ActivityRepository;
use Leads\Infrastructure\Persistence\CatalogRepository;
use Leads\Infrastructure\Persistence\DbTime;
use Leads\Infrastructure\Persistence\LeadRepository;

/**
 * Casos de uso sobre leads: consulta, alta, edición, flujo de llamadas e ingesta
 * (alta o fusión de duplicados) compartida con la importación.
 */
final class LeadService
{
    public const INGEST_CREATED = 'creado';
    public const INGEST_MERGED = 'fusionado';

    public function __construct(
        private readonly LeadRepository $leads,
        private readonly ActivityRepository $activity,
        private readonly CatalogRepository $catalog,
        private readonly LeadWorkflow $workflow,
        private readonly WorkflowSettings $settings,
        private readonly LeadPresenter $presenter,
        private readonly Clock $clock,
        private readonly string $timezone = 'Europe/Madrid',
    ) {
    }

    // ── Consulta ────────────────────────────────────────────────────────────

    public function search(array $query, array $viewer): array
    {
        $search = LeadSearch::fromQuery($query);
        $now = $this->clock->now();
        $result = $this->leads->search($search, $now, $this->timezone);

        return [
            'items' => array_map(fn (array $row) => $this->presenter->present($row, $now, $viewer), $result['items']),
            'total' => $result['total'],
            'pagina' => $search->page,
            'porPagina' => $search->perPage,
            'contadores' => $this->leads->countByInbox($now),
        ];
    }

    public function counters(): array
    {
        return $this->leads->countByInbox($this->clock->now());
    }

    public function get(int $id, array $viewer): array
    {
        $lead = $this->present($this->findOrFail($id), $viewer);
        $lead['historial'] = $this->activity->forLead($id);

        return $lead;
    }

    // ── Alta y edición ──────────────────────────────────────────────────────

    /**
     * Alta manual. Si ya existe alguien con ese correo o teléfono se avisa (409)
     * salvo que se pida expresamente fusionar.
     */
    public function create(array $data, array $viewer): array
    {
        $input = $this->inputFromForm($data, LeadOrigin::MANUAL);
        if (!$input->hasContactData()) {
            throw new \InvalidArgumentException('Indica al menos un nombre, un correo o un teléfono');
        }

        $duplicate = $this->leads->findDuplicate($input->email, $input->phone?->digits());
        if ($duplicate !== null && !filter_var($data['fusionar'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw HttpException::conflict('Ya existe un lead con ese correo o teléfono', [
                'duplicado' => $this->present($duplicate, $viewer),
            ]);
        }

        $result = $this->leads->transactional(fn () => $this->ingest($input, (int) $viewer['id'], null, ActivityType::CREADO));

        return ['lead' => $this->get($result['id'], $viewer), 'accion' => $result['accion']];
    }

    public function update(int $id, array $data, array $viewer): array
    {
        $current = $this->findOrFail($id);
        $input = $this->inputFromForm($data, LeadOrigin::tryFrom((string) $current['origen']) ?? LeadOrigin::OTRO);
        if (!$input->hasContactData()) {
            throw new \InvalidArgumentException('Indica al menos un nombre, un correo o un teléfono');
        }
        $duplicate = $this->leads->findDuplicate($input->email, $input->phone?->digits(), $id);
        if ($duplicate !== null) {
            throw HttpException::conflict('Otro lead ya tiene ese correo o teléfono', [
                'duplicado' => $this->present($duplicate, $viewer),
            ]);
        }

        $now = $this->clock->now();
        $values = $this->contactColumns($input) + [
            'origen' => $input->origin->value,
            'comentarios' => $input->comments,
            'centro_id' => $input->centerId,
            'actualizado_en' => DbTime::toDb($now),
        ];
        $changed = $this->changedFields($current, $values);
        $studiesBefore = $this->leads->studyIds($id);
        sort($studiesBefore);
        $studiesAfter = $input->studyIds;
        sort($studiesAfter);
        if ($studiesBefore !== $studiesAfter) {
            $changed[] = 'estudios';
        }

        $this->leads->transactional(function () use ($id, $values, $input, $changed, $viewer, $now): void {
            $this->leads->save($id, $values);
            $this->leads->replaceStudies($id, $input->studyIds);
            if ($changed !== []) {
                $this->activity->log($id, (int) $viewer['id'], ActivityType::EDITADO, 'Datos editados: ' . implode(', ', $changed), ['campos' => $changed], $now);
            }
        });

        return $this->get($id, $viewer);
    }

    /**
     * Da de alta un lead o, si ya existe (mismo correo o teléfono), completa el
     * existente. Debe ejecutarse dentro de una transacción.
     *
     * @return array{id:int, accion:string, reactivado:bool}
     */
    public function ingest(LeadInput $input, ?int $userId, ?int $importId, string $activityType): array
    {
        $now = $this->clock->now();
        $existing = $this->leads->findDuplicate($input->email, $input->phone?->digits());

        if ($existing === null) {
            $id = $this->leads->create($this->contactColumns($input) + [
                'origen' => $input->origin->value,
                'comentarios' => $input->comments,
                'datos_extra' => $input->extra === [] ? null : json_encode($input->extra, JSON_UNESCAPED_UNICODE),
                'estado' => LeadStatus::NUEVO->value,
                'intentos_sin_respuesta' => 0,
                'centro_id' => $input->centerId,
                'fecha_origen' => DbTime::toDb($input->sourceDate),
                'ultima_entrada' => DbTime::toDb($now),
                'importacion_id' => $importId,
                'creado_por' => $userId,
                'creado_en' => DbTime::toDb($now),
                'actualizado_en' => DbTime::toDb($now),
            ]);
            $this->leads->addStudies($id, $input->studyIds);
            $summary = $activityType === ActivityType::IMPORTADO
                ? 'Importado (' . $input->origin->label() . ')'
                : 'Alta manual';
            $this->activity->log($id, $userId, $activityType, $summary, $importId ? ['importacion' => $importId] : [], $now);

            return ['id' => $id, 'accion' => self::INGEST_CREATED, 'reactivado' => false];
        }

        $id = (int) $existing['id'];
        $this->leads->save($id, $this->mergeColumns($existing, $input, $now));
        $this->leads->addStudies($id, $input->studyIds);
        $this->activity->log($id, $userId, ActivityType::FUSIONADO, 'Volvió a entrar por ' . $input->origin->label(), array_filter([
            'importacion' => $importId,
            'origen' => $input->origin->value,
            'campana' => $input->campaign,
        ]), $now);

        $transition = $this->workflow->reentry($this->stateOf($existing), $now);
        if ($transition !== null) {
            $this->applyTransition($id, $transition, $userId, $now);
        }

        return ['id' => $id, 'accion' => self::INGEST_MERGED, 'reactivado' => $transition !== null];
    }

    // ── Flujo comercial ─────────────────────────────────────────────────────

    public function lock(int $id, array $viewer): array
    {
        $this->findOrFail($id);
        $now = $this->clock->now();
        $until = $now->modify("+{$this->settings->lockMinutes} minutes");
        if (!$this->leads->lock($id, (int) $viewer['id'], $now, $until)) {
            $lead = $this->present($this->findOrFail($id), $viewer);
            $who = $lead['bloqueo']['usuario'] ?? 'otra persona';
            throw HttpException::conflict("{$who} está llamando a este lead ahora mismo", ['lead' => $lead]);
        }

        return $this->present($this->findOrFail($id), $viewer);
    }

    public function unlock(int $id, array $viewer): void
    {
        $this->leads->unlock($id, (int) $viewer['id']);
    }

    public function registerCall(int $id, string $outcome, ?string $note, array $viewer): array
    {
        $result = CallOutcome::tryFrom($outcome) ?? throw new \InvalidArgumentException('Resultado de llamada no válido');
        $lead = $this->findOrFail($id);
        if ($lead['telefono_digitos'] === null) {
            throw new \InvalidArgumentException('Este lead no tiene un teléfono válido');
        }
        $this->assertNotLockedByOther($lead, $viewer);

        $now = $this->clock->now();
        $transition = $this->workflow->registerCall($this->stateOf($lead), $result, $now);

        return $this->commit($id, $transition, $note, $viewer, $now, unlock: true);
    }

    /** Contesta y pide que le llamen un día y una hora concretos ("2026-09-24T17:00", hora de Madrid). */
    public function scheduleCall(int $id, string $when, ?string $note, array $viewer): array
    {
        $at = $this->parseLocalDateTime($when, 'Fecha de la llamada no válida');
        $lead = $this->findOrFail($id);
        if ($lead['telefono_digitos'] === null) {
            throw new \InvalidArgumentException('Este lead no tiene un teléfono válido');
        }
        $this->assertNotLockedByOther($lead, $viewer);

        $now = $this->clock->now();
        $transition = $this->workflow->scheduleCall($this->stateOf($lead), $at, $now);

        return $this->commit($id, $transition, $note, $viewer, $now, unlock: true);
    }

    /** Botón rápido de interés, sin registrar ninguna llamada. */
    public function markInterested(int $id, array $viewer): array
    {
        $lead = $this->findOrFail($id);

        return $this->commit($id, $this->workflow->markInterested($this->stateOf($lead)), null, $viewer, $this->clock->now());
    }

    public function scheduleAppointment(int $id, string $when, ?string $note, array $viewer): array
    {
        $at = $this->parseLocalDateTime($when, 'Fecha de la cita no válida');
        $lead = $this->findOrFail($id);
        $now = $this->clock->now();

        return $this->commit($id, $this->workflow->scheduleAppointment($this->stateOf($lead), $at), $note, $viewer, $now);
    }

    public function enroll(int $id, ?string $note, array $viewer): array
    {
        $lead = $this->findOrFail($id);

        return $this->commit($id, $this->workflow->enroll($this->stateOf($lead)), $note, $viewer, $this->clock->now());
    }

    public function discard(int $id, string $reason, array $viewer): array
    {
        $lead = $this->findOrFail($id);

        return $this->commit($id, $this->workflow->discard($this->stateOf($lead), $reason), null, $viewer, $this->clock->now(), unlock: true);
    }

    public function reopen(int $id, array $viewer): array
    {
        $lead = $this->findOrFail($id);

        return $this->commit($id, $this->workflow->reopen($this->stateOf($lead)), null, $viewer, $this->clock->now());
    }

    public function addNote(int $id, string $text, array $viewer): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('La nota está vacía');
        }
        $this->findOrFail($id);
        $now = $this->clock->now();
        $this->activity->log($id, (int) $viewer['id'], ActivityType::NOTA, mb_substr($text, 0, 255), mb_strlen($text) > 255 ? ['texto' => $text] : [], $now);
        $this->leads->save($id, ['actualizado_en' => DbTime::toDb($now)]);

        return $this->get($id, $viewer);
    }

    public function logWhatsapp(int $id, array $viewer): array
    {
        $lead = $this->present($this->findOrFail($id), $viewer);
        if ($lead['whatsappUrl'] === null) {
            throw new \InvalidArgumentException('Este lead no tiene un teléfono válido');
        }
        $this->activity->log($id, (int) $viewer['id'], ActivityType::WHATSAPP, 'WhatsApp abierto', [], $this->clock->now());

        return ['url' => $lead['whatsappUrl']];
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    public function findOrFail(int $id): array
    {
        return $this->leads->find($id) ?? throw HttpException::notFound('Lead no encontrado');
    }

    public function present(array $row, array $viewer): array
    {
        return $this->presenter->present($row, $this->clock->now(), $viewer);
    }

    public function stateOf(array $row): LeadState
    {
        return new LeadState(
            status: LeadStatus::from($row['estado']),
            attempts: (int) $row['intentos_sin_respuesta'],
            nextCall: DbTime::fromDb($row['proxima_llamada']),
            lastCall: DbTime::fromDb($row['ultima_llamada']),
            appointmentAt: DbTime::fromDb($row['cita_en']),
            discardReason: $row['motivo_descarte'],
            scheduledCall: DbTime::fromDb($row['llamada_agendada']),
        );
    }

    private function commit(int $id, Transition $transition, ?string $note, array $viewer, DateTimeImmutable $now, bool $unlock = false): array
    {
        $this->leads->transactional(function () use ($id, $transition, $note, $viewer, $now, $unlock): void {
            $detail = $transition->detail;
            $note = trim((string) $note);
            if ($note !== '') {
                $detail['nota'] = $note;
            }
            $this->applyTransition($id, new Transition($transition->state, $transition->activityType, $transition->summary, $detail), (int) $viewer['id'], $now);
            if ($unlock) {
                $this->leads->unlock($id, (int) $viewer['id']);
            }
        });

        return $this->get($id, $viewer);
    }

    private function applyTransition(int $id, Transition $transition, ?int $userId, DateTimeImmutable $now): void
    {
        $state = $transition->state;
        $this->leads->save($id, [
            'estado' => $state->status->value,
            'intentos_sin_respuesta' => $state->attempts,
            'proxima_llamada' => DbTime::toDb($state->nextCall),
            'ultima_llamada' => DbTime::toDb($state->lastCall),
            'cita_en' => DbTime::toDb($state->appointmentAt),
            'llamada_agendada' => DbTime::toDb($state->scheduledCall),
            'motivo_descarte' => $state->discardReason,
            'actualizado_en' => DbTime::toDb($now),
        ]);
        $this->activity->log($id, $userId, $transition->activityType, $transition->summary, $transition->detail, $now);
    }

    private function assertNotLockedByOther(array $lead, array $viewer): void
    {
        $until = DbTime::fromDb($lead['bloqueado_hasta']);
        if ($lead['bloqueado_por'] !== null && (int) $lead['bloqueado_por'] !== (int) $viewer['id'] && $until > $this->clock->now()) {
            throw HttpException::conflict(($lead['bloqueado_por_nombre'] ?? 'Otra persona') . ' está llamando a este lead ahora mismo');
        }
    }

    private function inputFromForm(array $data, LeadOrigin $defaultOrigin): LeadInput
    {
        $studyIds = $this->catalog->existingStudyIds(array_map('intval', (array) ($data['estudioIds'] ?? [])));
        $centerId = isset($data['centroId']) && (int) $data['centroId'] > 0 ? (int) $data['centroId'] : null;
        if ($centerId !== null && !$this->catalog->centerExists($centerId)) {
            throw new \InvalidArgumentException('El centro indicado no existe');
        }

        return LeadInput::fromValues([
            'nombre' => $data['nombre'] ?? '',
            'apellidos' => $data['apellidos'] ?? '',
            'email' => $data['email'] ?? null,
            'telefono' => $data['telefono'] ?? null,
            'ubicacion' => $data['ubicacion'] ?? null,
            'interes' => $data['interes'] ?? null,
            'estudioIds' => $studyIds,
            'origen' => LeadOrigin::tryFrom((string) ($data['origen'] ?? '')) ?? $defaultOrigin,
            'campana' => $data['campana'] ?? null,
            'comentarios' => $data['comentarios'] ?? null,
            'centroId' => $centerId,
        ]);
    }

    private function contactColumns(LeadInput $input): array
    {
        return [
            'nombre' => $input->firstName,
            'apellidos' => $input->lastName,
            'email' => $input->email,
            'email_normalizado' => $input->email,
            'telefono' => $input->displayPhone(),
            'telefono_digitos' => $input->phone?->digits(),
            'ubicacion' => $input->location,
            'interes_texto' => $input->interestText,
            'campana' => $input->campaign,
            'nombre_busqueda' => $input->nameSearchText(),
            'texto_busqueda' => $input->searchText(),
        ];
    }

    /** Completa un lead existente con lo nuevo sin pisar lo que ya había. */
    private function mergeColumns(array $existing, LeadInput $input, DateTimeImmutable $now): array
    {
        $fill = static fn (?string $old, ?string $new): ?string => ($old === null || $old === '') ? $new : $old;
        $notes = [];
        if ($input->email !== null && $existing['email'] !== null && $input->email !== $existing['email_normalizado']) {
            $notes[] = "Otro correo: {$input->email}";
        }
        if ($input->phone !== null && $existing['telefono_digitos'] !== null && $input->phone->digits() !== $existing['telefono_digitos']) {
            $notes[] = 'Otro teléfono: ' . $input->displayPhone();
        }
        if ($input->comments !== null && !str_contains((string) $existing['comentarios'], $input->comments)) {
            $notes[] = $input->comments;
        }
        $comments = $existing['comentarios'];
        if ($notes !== []) {
            $stamp = $now->setTimezone(new DateTimeZone($this->timezone))->format('d/m/Y');
            $comments = trim(($comments ?? '') . "\n[{$stamp} · {$input->origin->label()}] " . implode(' · ', $notes));
        }

        $interest = $existing['interes_texto'];
        if ($input->interestText !== null && Text::normalize($interest) !== Text::normalize($input->interestText)) {
            $interest = Text::truncate(trim(($interest ? $interest . ' · ' : '') . $input->interestText), 255);
        }

        $extra = $existing['datos_extra'] ? (json_decode($existing['datos_extra'], true) ?: []) : [];
        $extra = array_replace($extra, $input->extra);

        $merged = LeadInput::fromValues([
            'nombre' => $fill($existing['nombre'], $input->firstName),
            'apellidos' => $fill($existing['apellidos'], $input->lastName),
            'email' => $fill($existing['email'], $input->email),
            'telefono' => $existing['telefono'] ?: $input->rawPhone,
            'ubicacion' => $fill($existing['ubicacion'], $input->location),
            'interes' => $interest,
            'campana' => $fill($existing['campana'], $input->campaign),
        ]);

        return $this->contactColumns($merged) + [
            'comentarios' => $comments,
            'datos_extra' => $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE),
            'centro_id' => $existing['centro_id'] ?? $input->centerId,
            'fecha_origen' => $existing['fecha_origen'] ?? DbTime::toDb($input->sourceDate),
            'ultima_entrada' => DbTime::toDb($now),
            'actualizado_en' => DbTime::toDb($now),
        ];
    }

    private function changedFields(array $current, array $values): array
    {
        $labels = [
            'nombre' => 'nombre', 'apellidos' => 'apellidos', 'email' => 'correo', 'telefono' => 'teléfono',
            'ubicacion' => 'ubicación', 'interes_texto' => 'interés', 'campana' => 'campaña',
            'comentarios' => 'comentarios', 'centro_id' => 'centro', 'origen' => 'origen',
        ];
        $changed = [];
        foreach ($labels as $column => $label) {
            if ((string) ($current[$column] ?? '') !== (string) ($values[$column] ?? '')) {
                $changed[] = $label;
            }
        }

        return $changed;
    }

    /** Fecha y hora local ("2026-09-20T10:30") a UTC. */
    private function parseLocalDateTime(string $value, string $error): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', substr(trim($value), 0, 16), new DateTimeZone($this->timezone));
        if ($date === false) {
            throw new \InvalidArgumentException($error);
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }
}
