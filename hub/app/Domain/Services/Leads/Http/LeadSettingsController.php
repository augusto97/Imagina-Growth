<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Http;

use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Models\LeadSeller;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Support\LeadDefaults;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Workspaces\Models\User;
use App\Support\Ids\PrefixedId;
use App\Support\Phone\Phone;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de leads (M18): reglas y umbrales del puntaje, avisos al vendedor,
 * asignación, WhatsApp como origen, vendedores (con su WhatsApp cifrado) y
 * pipelines. Ver con `useLeads`; cambiar con `manageServices`.
 */
final class LeadSettingsController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function show(AiGateway $ai): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $ws = $this->current->get();
        $s = LeadSettings::for($ws->id);
        Pipelines::default($ws->id);

        return response()->json([
            'scoring' => $s->scoringRules(),
            'alerts' => $s->alertRules(),
            'assignment' => $s->assignmentRules(),
            'whatsapp_leads' => $s->whatsapp_leads,
            'ai_qualify' => $s->ai_qualify,
            'ai_brief' => $s->ai_brief,
            'assistant' => $s->assistantRules(),
            'ai_available' => $ai->available($ws->id),
            'signals' => LeadDefaults::SIGNALS,
            'free_email_domains' => LeadDefaults::FREE_EMAIL_DOMAINS,
            'sellers' => $this->sellers(),
            'pipelines' => LeadPipeline::query()->orderByDesc('is_default')->orderBy('created_at')->get()->map(fn (LeadPipeline $p) => LeadController::pipelinePayload($p))->values()->all(),
            'whatsapp_templates' => DB::table('templates')->where('workspace_id', $ws->id)->where('channel', 'whatsapp')->where('status', '!=', 'archived')
                ->orderBy('name')->limit(100)->get(['id', 'name', 'category'])->map(fn ($t) => ['id' => TemplateRef::encode((string) $t->id), 'name' => (string) $t->name, 'category' => (string) $t->category])->values()->all(),
            'can_manage' => Gate::allows('ws.manageServices'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $points = [];
        foreach (LeadDefaults::SIGNALS as $signal) {
            $points["scoring.points.{$signal}"] = ['sometimes', 'integer', 'between:0,100'];
        }
        $data = $request->validate($points + [
            'scoring' => ['sometimes', 'array'],
            'scoring.pricing_paths' => ['sometimes', 'array', 'max:30'],
            'scoring.pricing_paths.*' => ['string', 'regex:#^/[A-Za-z0-9/_\-.%]{0,120}$#'],
            'scoring.service_cities' => ['sometimes', 'array', 'max:300'],
            'scoring.service_cities.*' => ['string', 'max:80'],
            'scoring.personal_domains' => ['sometimes', 'array', 'max:100'],
            'scoring.personal_domains.*' => ['string', 'max:120', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'scoring.thresholds.warm' => ['sometimes', 'integer', 'between:1,99'],
            'scoring.thresholds.hot' => ['sometimes', 'integer', 'between:2,100'],
            'scoring.ai_max_points' => ['sometimes', 'integer', 'between:0,20'],
            'alerts' => ['sometimes', 'array'],
            'alerts.on_hot' => ['sometimes', 'boolean'],
            'alerts.on_new' => ['sometimes', 'boolean'],
            'alerts.channel' => ['sometimes', Rule::in(['whatsapp', 'email'])],
            'alerts.whatsapp_template_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'assignment' => ['sometimes', 'array'],
            'assignment.mode' => ['sometimes', Rule::in(['round_robin', 'rules', 'none'])],
            'whatsapp_leads' => ['sometimes', 'boolean'],
            'ai_qualify' => ['sometimes', 'boolean'],
            'ai_brief' => ['sometimes', 'boolean'],
            'assistant' => ['sometimes', 'array'],
            'assistant.stale_days' => ['sometimes', 'integer', 'between:1,90'],
            'assistant.whatsapp_template_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'assistant.digest' => ['sometimes', 'boolean'],
        ]);
        $ws = $this->current->get();
        $s = LeadSettings::for($ws->id);
        if (isset($data['scoring'])) {
            $scoring = array_replace_recursive((array) ($s->scoring ?? []), array_intersect_key($data['scoring'], LeadDefaults::SCORING));
            foreach (['pricing_paths', 'service_cities', 'personal_domains'] as $list) {
                if (array_key_exists($list, $data['scoring'])) {
                    $scoring[$list] = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), (array) $data['scoring'][$list]), fn ($v) => $v !== '')));
                }
            }
            $t = array_replace(LeadDefaults::SCORING['thresholds'], (array) ($scoring['thresholds'] ?? []));
            if ((int) $t['warm'] >= (int) $t['hot']) {
                throw ValidationException::withMessages(['scoring.thresholds.hot' => __('El umbral de «caliente» debe ser mayor que el de «tibio».')]);
            }
            $s->scoring = $scoring;
        }
        if (isset($data['alerts'])) {
            $alerts = array_replace((array) ($s->alerts ?? []), $data['alerts']);
            $tpl = $alerts['whatsapp_template_id'] ?? null;
            if (is_string($tpl) && $tpl !== '' && TemplateRef::resolve($ws->id, 'whatsapp', $tpl, null) === null) {
                throw ValidationException::withMessages(['alerts.whatsapp_template_id' => __('Esa plantilla de WhatsApp no existe.')]);
            }
            $s->alerts = $alerts;
        }
        if (isset($data['assistant'])) {
            $assistant = array_replace((array) ($s->assistant ?? []), array_intersect_key($data['assistant'], LeadDefaults::ASSISTANT));
            $tpl = $assistant['whatsapp_template_id'] ?? null;
            if (is_string($tpl) && $tpl !== '') {
                $id = TemplateRef::resolve($ws->id, 'whatsapp', $tpl, null);
                // El seguimiento es comercial: la plantilla debe ser de marketing (no se disfraza de utilidad).
                if ($id === null || DB::table('templates')->where('workspace_id', $ws->id)->where('id', $id)->value('category') !== 'marketing') {
                    throw ValidationException::withMessages(['assistant.whatsapp_template_id' => __('Elige una plantilla de WhatsApp de marketing aprobada.')]);
                }
            }
            $s->assistant = $assistant;
        }
        if (isset($data['assignment'])) {
            $s->assignment = array_replace((array) ($s->assignment ?? []), $data['assignment']);
        }
        foreach (['whatsapp_leads', 'ai_qualify', 'ai_brief'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $s->{$flag} = (bool) $data[$flag];
            }
        }
        $s->workspace_id = $ws->id;
        $s->save();

        return $this->show(app(AiGateway::class));
    }

    public function storeSeller(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $data = $this->validateSeller($request, true);
        $user = $this->member((string) $data['user_id']);
        if (LeadSeller::query()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['user_id' => __('Esa persona ya atiende leads.')]);
        }
        LeadSeller::query()->create([
            'workspace_id' => $this->current->id(),
            'user_id' => $user->id,
            'phone' => $this->phone($data['phone'] ?? null),
            'services' => $this->services($data['services'] ?? []),
            'active' => (bool) ($data['active'] ?? true),
        ]);

        return response()->json(['sellers' => $this->sellers()], 201);
    }

    public function updateSeller(Request $request, string $seller): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $s = LeadSeller::findByPublicId($seller) ?? abort(404);
        $data = $this->validateSeller($request, false);
        if (array_key_exists('phone', $data)) {
            $s->phone = $this->phone($data['phone']);
        }
        if (array_key_exists('services', $data)) {
            $s->services = $this->services((array) $data['services']);
        }
        if (array_key_exists('active', $data)) {
            $s->active = (bool) $data['active'];
        }
        $s->save();

        return response()->json(['sellers' => $this->sellers()]);
    }

    public function destroySeller(string $seller): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        (LeadSeller::findByPublicId($seller) ?? abort(404))->delete();

        return response()->json(['sellers' => $this->sellers()]);
    }

    public function storePipeline(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $data = $this->validatePipeline($request);
        $p = LeadPipeline::query()->create(['workspace_id' => $this->current->id(), 'name' => $data['name'], 'stages' => $data['stages'], 'is_default' => false]);

        return response()->json(LeadController::pipelinePayload($p), 201);
    }

    public function updatePipeline(Request $request, string $pipeline): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $p = LeadPipeline::findByPublicId($pipeline) ?? abort(404);
        $data = $this->validatePipeline($request);
        $keys = array_column($data['stages'], 'key');
        $used = Lead::query()->where('pipeline_id', $p->id)->distinct()->pluck('stage')->map(fn ($v) => (string) $v)->all();
        $missing = array_values(array_diff($used, $keys));
        if ($missing !== []) {
            throw ValidationException::withMessages(['stages' => __('Hay leads en etapas que quitaste (:stages). Muévelos antes de quitarlas.', ['stages' => implode(', ', $missing)])]);
        }
        $p->forceFill(['name' => $data['name'], 'stages' => $data['stages']])->save();

        return response()->json(LeadController::pipelinePayload($p));
    }

    public function destroyPipeline(string $pipeline): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $p = LeadPipeline::findByPublicId($pipeline) ?? abort(404);
        if ($p->is_default) {
            throw ValidationException::withMessages(['pipeline' => __('El pipeline principal no se puede borrar.')]);
        }
        if (Lead::query()->where('pipeline_id', $p->id)->exists()) {
            throw ValidationException::withMessages(['pipeline' => __('Este pipeline tiene leads. Muévelos antes de borrarlo.')]);
        }
        $p->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array{name: string, stages: list<array{key: string, label: string, kind: string}>} */
    private function validatePipeline(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'stages' => ['required', 'array', 'min:3', 'max:12'],
            'stages.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,31}$/'],
            'stages.*.label' => ['required', 'string', 'max:60'],
            'stages.*.kind' => ['required', Rule::in(['open', 'won', 'lost'])],
        ]);
        $stages = array_map(fn (array $s) => ['key' => (string) $s['key'], 'label' => trim((string) $s['label']), 'kind' => (string) $s['kind']], array_values($data['stages']));
        $keys = array_column($stages, 'key');
        $kinds = array_count_values(array_column($stages, 'kind'));
        if (count(array_unique($keys)) !== count($keys)) {
            throw ValidationException::withMessages(['stages' => __('Cada etapa necesita una clave distinta.')]);
        }
        if (($kinds['open'] ?? 0) < 1 || ($kinds['won'] ?? 0) !== 1 || ($kinds['lost'] ?? 0) !== 1) {
            throw ValidationException::withMessages(['stages' => __('El pipeline necesita al menos una etapa abierta, una de ganado y una de perdido.')]);
        }

        return ['name' => trim((string) $data['name']), 'stages' => $stages];
    }

    /** @return array<string, mixed> */
    private function validateSeller(Request $request, bool $creating): array
    {
        return $request->validate([
            'user_id' => [$creating ? 'required' : 'prohibited', 'string', 'max:40'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'services' => ['sometimes', 'array', 'max:30'],
            'services.*' => ['string', 'max:80'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function phone(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $e164 = Phone::toE164($raw, (string) ($this->current->get()->setting('default_phone_country') ?? $this->current->get()->country ?? 'CO'));
        if ($e164 === null) {
            throw ValidationException::withMessages(['phone' => __('Ese número de WhatsApp no es válido.')]);
        }

        return $e164;
    }

    /**
     * @param  array<mixed>  $services
     * @return list<string>
     */
    private function services(array $services): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 80), $services), fn ($v) => $v !== '')));
    }

    private function member(string $publicId): User
    {
        $id = PrefixedId::decode(User::ID_PREFIX, $publicId);
        $user = $id !== null ? $this->current->get()->users()->where('users.id', $id)->first() : null;
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user_id' => __('Esa persona no es del equipo.')]);
        }

        return $user;
    }

    /** @return list<array<string, mixed>> */
    private function sellers(): array
    {
        $sellers = LeadSeller::query()->orderBy('created_at')->get();
        $users = User::query()->whereIn('id', $sellers->pluck('user_id')->all())->get(['id', 'name'])->keyBy('id');
        $open = Lead::query()->whereNull('won_at')->whereNull('lost_at')->whereIn('owner_id', $sellers->pluck('user_id')->all())
            ->selectRaw('owner_id, COUNT(*) AS n')->groupBy('owner_id')->pluck('n', 'owner_id');

        return array_values($sellers->map(fn (LeadSeller $s) => [
            'id' => $s->publicId(),
            'user' => ['id' => 'usr_'.$s->user_id, 'name' => $users->get($s->user_id)?->name],
            'phone' => is_string($s->phone) && $s->phone !== '' ? Pii::maskString($s->phone) : null,
            'services' => $s->services ?? [],
            'active' => $s->active,
            'open_leads' => (int) ($open[$s->user_id] ?? 0),
            'last_assigned_at' => $s->last_assigned_at?->toIso8601String(),
        ])->all());
    }
}
