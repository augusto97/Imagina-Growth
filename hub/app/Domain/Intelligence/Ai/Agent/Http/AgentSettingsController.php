<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Agent\Http;

use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Intelligence\Ai\Agent\SalesAgent;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Ajustes del agente de ventas y «Probar el agente» (M11). */
final class AgentSettingsController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function show(AiGateway $ai): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $ws = $this->current->get();

        return response()->json([
            'settings' => self::present(AgentSettings::current($ws->id)),
            'ai_available' => $ai->available($ws->id),
            'store_connected' => Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->exists(),
            'can_manage' => Gate::allows('ws.manageInbox'),
        ]);
    }

    public function update(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageInbox');
        $ws = $this->current->get();
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'default_mode' => ['required', Rule::in(AgentSettings::MODES)],
            'scope' => ['nullable', 'string', 'max:500'],
            'tone' => ['nullable', 'string', 'max:255'],
            'policies' => ['array'],
            'policies.*' => ['nullable', 'string', 'max:1500'],
            'handoff_rules' => ['array'],
            'handoff_rules.keywords' => ['array', 'max:30'],
            'handoff_rules.keywords.*' => ['string', 'max:40'],
            'handoff_rules.intents' => ['array'],
            'handoff_rules.intents.*' => [Rule::in(['complaint', 'support', 'purchase'])],
            'handoff_rules.hours' => ['array'],
            'handoff_rules.hours.mode' => [Rule::in(['always', 'out_of_hours'])],
            'handoff_rules.hours.days' => ['array'],
            'handoff_rules.hours.days.*' => ['integer', 'between:1,7'],
            'handoff_rules.hours.start' => ['nullable', 'date_format:H:i'],
            'handoff_rules.hours.end' => ['nullable', 'date_format:H:i', 'after:handoff_rules.hours.start'],
            'discount_rules' => ['array'],
            'discount_rules.enabled' => ['boolean'],
            'discount_rules.coupon_code' => ['nullable', 'string', 'max:40', 'required_if:discount_rules.enabled,true'],
            'discount_rules.min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'discount_rules.description' => ['nullable', 'string', 'max:160', 'required_if:discount_rules.enabled,true'],
            'max_steps' => ['required', 'integer', 'between:2,10'],
        ], [
            'discount_rules.coupon_code.required_if' => __('Escribe el código del cupón que el agente puede aplicar.'),
            'discount_rules.description.required_if' => __('Describe el descuento como el agente lo va a ofrecer.'),
            'handoff_rules.hours.end.after' => __('La hora de cierre debe ser después de la de apertura.'),
        ]);
        $policies = array_intersect_key((array) ($data['policies'] ?? []), array_flip(AgentSettings::POLICY_KEYS));
        $settings = AgentSettings::current($ws->id);
        $before = $settings->exists ? self::present($settings) : null;
        $settings->fill([
            'workspace_id' => $ws->id, 'enabled' => $data['enabled'], 'default_mode' => $data['default_mode'],
            'scope' => $data['scope'] ?? null, 'tone' => $data['tone'] ?? null, 'policies' => array_map(fn ($v) => trim((string) $v), $policies),
            'handoff_rules' => array_replace(AgentSettings::defaultHandoff(), (array) ($data['handoff_rules'] ?? [])),
            'discount_rules' => ['enabled' => (bool) ($data['discount_rules']['enabled'] ?? false), 'coupon_code' => $data['discount_rules']['coupon_code'] ?? null,
                'min_subtotal' => $data['discount_rules']['min_subtotal'] ?? null, 'description' => $data['discount_rules']['description'] ?? null],
            'max_steps' => $data['max_steps'], 'updated_by' => $request->user()?->getAuthIdentifier(),
        ])->save();
        $audit->handle('agent.settings_updated', null, ['before' => $before, 'after' => self::present($settings)], $ws->id, null, (string) $request->user()?->getAuthIdentifier());

        return response()->json(['settings' => self::present($settings)]);
    }

    public function sandbox(Request $request, SalesAgent $agent): JsonResponse
    {
        Gate::authorize('ws.manageInbox');
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:20'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:1000'],
        ]);
        $turn = $agent->sandbox($this->current->get(), array_values($data['messages']));

        return response()->json([
            'id' => $turn->publicId(), 'status' => $turn->status, 'reply' => $turn->suggestion, 'handoff_reason' => $turn->handoff_reason,
            'flagged' => $turn->flagged, 'tool_calls' => $turn->tool_calls ?? [], 'validation' => $turn->validation,
        ]);
    }

    /** @return array<string, mixed> */
    private static function present(AgentSettings $s): array
    {
        $policies = [];
        foreach (AgentSettings::POLICY_KEYS as $key) {
            $policies[$key] = (string) (($s->policies ?? [])[$key] ?? '');
        }
        $handoff = array_replace(AgentSettings::defaultHandoff(), (array) ($s->handoff_rules ?? []));
        $handoff['hours'] = array_replace(['mode' => 'always', 'days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '18:00'], (array) ($handoff['hours'] ?? []));
        $discount = array_replace(['enabled' => false, 'coupon_code' => null, 'min_subtotal' => null, 'description' => null], (array) ($s->discount_rules ?? []));

        return [
            'enabled' => (bool) $s->enabled, 'default_mode' => $s->default_mode ?: 'off', 'scope' => $s->scope, 'tone' => $s->tone,
            'policies' => $policies, 'handoff_rules' => $handoff, 'discount_rules' => $discount, 'max_steps' => (int) ($s->max_steps ?: 6),
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
