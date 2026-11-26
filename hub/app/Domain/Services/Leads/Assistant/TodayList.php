<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Assistant;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Services\Leads\Brief\LeadBrief;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadAssistantMark;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Models\LeadTask;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * La lista del día del vendedor, «Hoy» (M18, auditoría §5.1 función 6). Un ítem por
 * lead, el más urgente, más las tareas y llamadas que vencen hoy:
 *
 * 1. `reply`: el cliente escribió y nadie le ha contestado (la conversación espera).
 * 2. `first_contact`: lead caliente (por puntaje o por lo que dijo en el chat) sin contactar.
 * 3. `task`: tarea o llamada de hoy o vencida (la crea una persona o el flujo).
 * 4. `next_step`: el próximo paso del lead tenía fecha de hoy o antes.
 * 5. `stale`: lleva X días quieto en su etapa (Ajustes de leads, 7 por defecto).
 *
 * Lo que el vendedor marcó hecho no vuelve hasta que la situación cambie (la clave
 * lleva su momento); lo pospuesto vuelve en su fecha. `ownerId` null = todos (la
 * dueña); `none` = sin asignar. Corre con el espacio de trabajo activo.
 */
final class TodayList
{
    public const LIMIT = 60;

    public const ORDER = ['reply' => 1, 'first_contact' => 2, 'task' => 3, 'next_step' => 4, 'stale' => 5];

    /**
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>, stale_days: int}
     */
    public function handle(Workspace $workspace, ?string $ownerId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        [$visible, $leads, $staleDays] = $this->visible($workspace, $ownerId, $now);

        return ['items' => $this->present($workspace->id, array_slice($visible, 0, self::LIMIT), $leads, $now), 'counts' => self::count($visible), 'stale_days' => $staleDays];
    }

    /**
     * Solo cuántas hay de cada tipo, sin armar las tarjetas (sin descifrar contactos): lo que pide el Inicio.
     *
     * @return array<string, int>
     */
    public function counts(Workspace $workspace, ?string $ownerId, ?CarbonImmutable $now = null): array
    {
        return self::count($this->visible($workspace, $ownerId, $now ?? CarbonImmutable::now())[0]);
    }

    /**
     * @param  list<array<string, mixed>>  $visible
     * @return array<string, int>
     */
    private static function count(array $visible): array
    {
        $counts = array_fill_keys(array_keys(self::ORDER), 0);
        foreach ($visible as $c) {
            $counts[$c['kind']]++;
        }

        return $counts;
    }

    /**
     * Lo que toca hoy, en orden, sin lo que el vendedor marcó hecho o pospuso.
     *
     * @return array{0: list<array<string, mixed>>, 1: Collection<int, Lead>, 2: int}
     */
    private function visible(Workspace $workspace, ?string $ownerId, CarbonImmutable $now): array
    {
        $rules = LeadSettings::for($workspace->id)->assistantRules();
        $endOfDay = $now->setTimezone($workspace->timezone ?: 'America/Bogota')->endOfDay()->utc();
        $staleBefore = $now->subDays($rules['stale_days']);
        $owner = fn ($q) => match (true) {
            $ownerId === null => $q,
            $ownerId === 'none' => $q->whereNull('owner_id'),
            default => $q->where('owner_id', $ownerId),
        };

        $leads = $owner(Lead::query()->whereNull('won_at')->whereNull('lost_at'))->orderByDesc('last_activity_at')->limit(1000)->get();
        $waiting = Conversation::query()->whereIn('contact_id', $leads->pluck('contact_id')->unique()->values()->all())
            ->whereNotNull('awaiting_since')->where('status', '!=', 'closed')->orderBy('awaiting_since')->get()->unique('contact_id')->keyBy('contact_id');

        $candidates = [];
        foreach ($leads as $lead) {
            $item = $this->leadItem($lead, $waiting->get($lead->contact_id), $endOfDay, $staleBefore, $now);
            if ($item !== null) {
                $candidates[] = $item;
            }
        }
        $tasks = $owner(LeadTask::query()->where('status', 'open')->where('due_at', '<=', $endOfDay))->orderBy('due_at')->limit(200)->get();
        foreach ($tasks as $task) {
            $candidates[] = ['key' => 'task:'.$task->publicId(), 'kind' => 'task', 'lead_id' => $task->lead_id, 'task' => $task, 'at' => $task->due_at->toImmutable(), 'due_at' => $task->due_at->toIso8601String()];
        }

        $marks = LeadAssistantMark::query()->whereIn('item_key', array_column($candidates, 'key'))->get()->keyBy('item_key');
        $visible = array_values(array_filter($candidates, function (array $c) use ($marks, $now): bool {
            $mark = $marks->get($c['key']);
            if (! $mark instanceof LeadAssistantMark) {
                return true;
            }

            return $mark->status === 'snoozed' && $mark->until !== null && $mark->until->lessThanOrEqualTo($now);
        }));
        usort($visible, fn (array $a, array $b) => [self::ORDER[$a['kind']], $a['at']->getTimestamp()] <=> [self::ORDER[$b['kind']], $b['at']->getTimestamp()]);

        return [$visible, $leads, (int) $rules['stale_days']];
    }

    /** @return array<string, mixed>|null */
    private function leadItem(Lead $lead, ?Conversation $waiting, CarbonImmutable $endOfDay, CarbonImmutable $staleBefore, CarbonImmutable $now): ?array
    {
        $id = $lead->publicId();
        if ($waiting !== null && $waiting->awaiting_since !== null) {
            $since = CarbonImmutable::instance($waiting->awaiting_since);

            return ['key' => 'reply:'.$id.':'.$since->getTimestamp(), 'kind' => 'reply', 'lead_id' => $lead->id, 'at' => $since, 'since' => $since->toIso8601String()];
        }
        $hot = $lead->temperature === 'hot' || LeadBrief::value($lead->brief, 'temperature') === 'hot';
        if ($hot && $lead->first_response_at === null) {
            $at = CarbonImmutable::instance($lead->created_at ?? $now);

            return ['key' => 'hot:'.$id, 'kind' => 'first_contact', 'lead_id' => $lead->id, 'at' => $at, 'since' => $at->toIso8601String()];
        }
        if ($lead->next_step_at !== null && $lead->next_step_at->lessThanOrEqualTo($endOfDay)) {
            $at = CarbonImmutable::instance($lead->next_step_at);

            return ['key' => 'next:'.$id.':'.$at->getTimestamp(), 'kind' => 'next_step', 'lead_id' => $lead->id, 'at' => $at, 'due_at' => $at->toIso8601String()];
        }
        $stageSince = $lead->stage_changed_at ?? $lead->created_at;
        $quiet = $lead->last_activity_at === null || $lead->last_activity_at->lessThanOrEqualTo($staleBefore);
        if ($stageSince !== null && $stageSince->lessThanOrEqualTo($staleBefore) && $quiet) {
            $at = CarbonImmutable::instance($stageSince);

            return ['key' => 'stale:'.$id.':'.$at->getTimestamp(), 'kind' => 'stale', 'lead_id' => $lead->id, 'at' => $at, 'since' => $at->toIso8601String(),
                'days' => (int) floor($at->diffInDays($now, true))];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  Collection<int, Lead>  $open
     * @return list<array<string, mixed>>
     */
    private function present(string $workspaceId, array $items, Collection $open, CarbonImmutable $now): array
    {
        $byId = $open->keyBy('id');
        $missing = array_values(array_diff(array_filter(array_column($items, 'lead_id')), $byId->keys()->all()));
        if ($missing !== []) {
            foreach (Lead::query()->whereIn('id', $missing)->get() as $l) {
                $byId->put($l->id, $l);
            }
        }
        $leads = array_values(array_filter(array_map(fn ($id) => $byId->get($id), array_filter(array_column($items, 'lead_id')))));
        $contacts = Contact::query()->whereIn('id', array_map(fn (Lead $l) => $l->contact_id, $leads))->get()->keyBy('id');
        $userIds = array_filter([...array_map(fn (Lead $l) => $l->owner_id, $leads), ...array_map(fn (array $i) => ($i['task'] ?? null)?->owner_id, $items)]);
        $users = User::query()->whereIn('id', array_values(array_unique($userIds)))->get(['id', 'name'])->keyBy('id');
        $pipelines = LeadPipeline::query()->get()->keyBy('id');
        $default = Pipelines::default($workspaceId);

        return array_map(function (array $i) use ($byId, $contacts, $users, $pipelines, $default, $now): array {
            $lead = $i['lead_id'] !== null ? $byId->get($i['lead_id']) : null;
            $contact = $lead !== null ? $contacts->get($lead->contact_id) : null;
            $pipeline = $lead !== null && $lead->pipeline_id !== null ? ($pipelines->get($lead->pipeline_id) ?? $default) : $default;
            /** @var LeadTask|null $task */
            $task = $i['task'] ?? null;
            $owner = $lead?->owner_id !== null ? $users->get($lead->owner_id) : ($task?->owner_id !== null ? $users->get($task->owner_id) : null);
            $name = $contact?->displayName() ?? '';

            return [
                'key' => $i['key'],
                'kind' => $i['kind'],
                'since' => $i['since'] ?? null,
                'due_at' => $i['due_at'] ?? null,
                'days' => $i['days'] ?? null,
                'overdue' => isset($i['due_at']) && CarbonImmutable::parse($i['due_at'])->lessThan($now->startOfDay()),
                'lead' => $lead === null ? null : [
                    'id' => $lead->publicId(),
                    'name' => $name !== '' ? $name : null,
                    'city' => is_string($contact?->getAttribute('city')) ? $contact->getAttribute('city') : null,
                    'service' => $lead->service ?? LeadBrief::value($lead->brief, 'need'),
                    'stage' => $lead->stage,
                    'stage_label' => $pipeline->stage($lead->stage)['label'] ?? $lead->stage,
                    'temperature' => $lead->temperature,
                    'brief_temperature' => LeadBrief::value($lead->brief, 'temperature'),
                    'score' => $lead->score,
                    'next_step' => $lead->next_step,
                ],
                'task' => $task === null ? null : ['id' => $task->publicId(), 'kind' => $task->kind, 'title' => $task->title, 'source' => $task->source],
                'owner' => $owner !== null ? ['id' => $owner->publicId(), 'name' => $owner->name] : null,
            ];
        }, $items);
    }
}
