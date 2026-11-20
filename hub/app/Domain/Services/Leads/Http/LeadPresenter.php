<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Http;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Services\Leads\Brief\LeadBrief;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Support\LeadFacts;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;

/** Forma de un lead para la SPA. Cifras y textos salen del lead; nada se calcula en el navegador. */
final class LeadPresenter
{
    /**
     * @param  iterable<int, Lead>  $leads
     * @return list<array<string, mixed>>
     */
    public static function rows(Workspace $workspace, iterable $leads): array
    {
        $list = collect($leads);
        $contacts = Contact::query()->whereIn('id', $list->pluck('contact_id')->unique()->values()->all())->get()->keyBy('id');
        $users = User::query()->whereIn('id', $list->pluck('owner_id')->filter()->unique()->values()->all())->get(['id', 'name'])->keyBy('id');

        return array_values($list->map(fn (Lead $l) => self::row($workspace, $l, $contacts->get($l->contact_id), $l->owner_id !== null ? $users->get($l->owner_id) : null))->all());
    }

    /** @return array<string, mixed> */
    public static function row(Workspace $workspace, Lead $l, ?Contact $contact, ?User $owner): array
    {
        $name = $contact?->displayName() ?? '';

        return [
            'id' => $l->publicId(),
            'stage' => $l->stage,
            'pipeline_id' => $l->pipeline_id !== null ? 'lpl_'.$l->pipeline_id : null,
            'contact' => $contact !== null ? [
                'id' => $contact->publicId(),
                'name' => $name !== '' ? $name : null,
                'city' => is_string($contact->getAttribute('city')) ? $contact->getAttribute('city') : null,
            ] : null,
            'service' => $l->service,
            'value' => $l->value_ws,
            'currency' => $workspace->currency,
            'owner' => $owner !== null ? ['id' => $owner->publicId(), 'name' => $owner->name] : null,
            'score' => $l->score,
            'temperature' => $l->temperature,
            'origin' => $l->origin,
            'channel' => is_string($l->source['channel'] ?? null) ? $l->source['channel'] : null,
            'channel_label' => LeadFacts::channelLabel(is_string($l->source['channel'] ?? null) ? $l->source['channel'] : null),
            'next_step' => $l->next_step,
            'next_step_at' => $l->next_step_at?->toIso8601String(),
            'stage_changed_at' => $l->stage_changed_at?->toIso8601String(),
            'first_response_at' => $l->first_response_at?->toIso8601String(),
            'last_activity_at' => $l->last_activity_at?->toIso8601String(),
            'won_at' => $l->won_at?->toIso8601String(),
            'lost_at' => $l->lost_at?->toIso8601String(),
            'lost_reason' => $l->lost_reason,
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }

    /**
     * El lead del contacto en el panel lateral del chat de la bandeja: etapa, temperatura y la
     * ficha que llenó la IA con sus frases de origen. `can_open`: si quien mira puede abrir el lead.
     *
     * @return array<string, mixed>
     */
    public static function panel(Lead $l, bool $canOpen): array
    {
        $pipeline = Pipelines::for($l);
        $stage = $pipeline->stage($l->stage);
        $owner = $l->owner_id !== null ? User::query()->find($l->owner_id, ['id', 'name']) : null;

        return [
            'id' => $l->publicId(),
            'stage' => $l->stage,
            'stage_label' => $stage['label'] ?? $l->stage,
            'service' => $l->service,
            'score' => $l->score,
            'temperature' => $l->temperature,
            'owner' => $owner !== null ? ['id' => $owner->publicId(), 'name' => $owner->name] : null,
            'brief' => LeadBrief::present($l->brief),
            'brief_updated_at' => $l->brief_updated_at?->toIso8601String(),
            'can_open' => $canOpen,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Workspace $workspace, Lead $l): array
    {
        $contact = Contact::query()->find($l->contact_id);
        $owner = $l->owner_id !== null ? User::query()->find($l->owner_id, ['id', 'name']) : null;
        $activities = LeadActivity::query()->where('lead_id', $l->id)->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();
        $actors = User::query()->whereIn('id', $activities->pluck('actor')->filter(fn ($a) => ! in_array($a, ['system', 'ai', 'user', 'api'], true))->unique()->values()->all())->get(['id', 'name'])->keyBy('id');
        $source = (array) ($l->source ?? []);

        return self::row($workspace, $l, $contact, $owner) + [
            'pipeline_stages' => Pipelines::for($l)->stages,
            'email' => $contact?->email,
            'phone' => $contact?->phone,
            'source' => array_intersect_key($source, array_flip(['channel', 'utm_source', 'utm_medium', 'utm_campaign', 'page', 'form_name', 'referrer_host'])) + [
                'has_gclid' => isset($source['gclid']) || isset($source['gbraid']) || isset($source['wbraid']),
                'has_fbclid' => isset($source['fbclid']),
            ],
            'score_reasons' => array_map(fn (array $r) => [
                'signal' => (string) ($r['signal'] ?? ''),
                'points' => (int) ($r['points'] ?? 0),
                'at' => $r['at'] ?? null,
                'detail' => $r['detail'] ?? null,
                'reason' => $r['reason'] ?? null,
            ], $l->score_reasons ?? []),
            'brief' => LeadBrief::present($l->brief),
            'brief_updated_at' => $l->brief_updated_at?->toIso8601String(),
            'offline_conversion_at' => $l->offline_conversion_at?->toIso8601String(),
            'external_crm_id' => $l->external_crm_id,
            'activities' => $activities->map(function (LeadActivity $a) use ($actors): array {
                $data = (array) ($a->data ?? []);
                if ($a->kind === 'note' && is_string($data['body'] ?? null)) {
                    try {
                        $data['body'] = decrypt($data['body']);
                    } catch (\Throwable) {
                        $data['body'] = null;
                    }
                }
                $actor = $actors->get($a->actor);

                return [
                    'id' => $a->id,
                    'kind' => $a->kind,
                    'actor' => in_array($a->actor, ['system', 'ai', 'api'], true) ? $a->actor : 'user',
                    'actor_name' => $actor?->name,
                    'data' => $data,
                    'at' => $a->created_at->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }
}
