<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Http;

use App\Domain\Campaigns\Actions\CampaignSender;
use App\Domain\Campaigns\Actions\ReviewCampaign;
use App\Domain\Campaigns\Audience\AudienceQuery;
use App\Domain\Campaigns\Audience\AudienceReach;
use App\Domain\Campaigns\Jobs\SendCampaignBatch;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Queries\CampaignReport;
use App\Domain\Campaigns\Support\CommercialCalendar;
use App\Domain\Campaigns\Support\SendWindowExplainer;
use App\Domain\Campaigns\Support\WaRateCard;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * API de campañas de la SPA (modulos.md M10). Ver: viewDashboards; crear,
 * editar, aprobar, pausar o cancelar: manageMarketing (queda en la auditoría).
 */
final class CampaignController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET campaigns?status= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $status = $request->query('status');
        $campaigns = Campaign::query()
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByRaw("FIELD(status, 'sending', 'scheduled', 'paused', 'draft', 'sent', 'cancelled')")
            ->orderByDesc('updated_at')->limit(500)->get();
        // Para las que ya empezaron: destinatarios reales (sin el grupo de control) y enviados, en una consulta.
        $counts = DB::table('campaign_recipients')->where('workspace_id', $this->current->id())
            ->whereIn('campaign_id', $campaigns->whereNotNull('started_at')->pluck('id')->all())
            ->groupBy('campaign_id')
            ->selectRaw("campaign_id, SUM(CASE WHEN is_holdout = 0 THEN 1 ELSE 0 END) AS recipients, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent")
            ->get()->keyBy('campaign_id');

        return response()->json(['data' => $campaigns->map(fn (Campaign $c) => CampaignPresenter::make($c) + [
            'recipients' => isset($counts[$c->id]) ? (int) $counts[$c->id]->recipients : null,
            'sent' => isset($counts[$c->id]) ? (int) $counts[$c->id]->sent : null,
        ])->values()]);
    }

    /** POST campaigns {name, channel} */
    public function store(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'channel' => ['required', Rule::in(Campaign::CHANNELS)],
        ]);
        /** @var User $user */
        $user = $request->user();
        $campaign = Campaign::query()->create($data + [
            'workspace_id' => $this->current->id(),
            'audience' => ['segments_include' => [], 'segments_exclude' => [], 'all_contacts' => false, 'rules_include' => [], 'rules_exclude' => []],
            'created_by' => $user->id,
        ]);
        $audit->handle('campaign.created', $campaign, ['name' => $campaign->name, 'channel' => $campaign->channel], $this->current->id());

        return response()->json(CampaignPresenter::make($campaign, true), 201);
    }

    /** GET campaigns/{campaign} */
    public function show(string $campaign): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(CampaignPresenter::make($this->find($campaign), true));
    }

    /** PATCH campaigns/{campaign}: cualquier paso del asistente mientras sea editable. */
    public function update(Request $request, string $campaign): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($campaign);
        if (! $model->isEditable()) {
            throw ValidationException::withMessages(['status' => __('La campaña ya empezó a enviarse: no se puede editar.')]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'channel' => ['sometimes', Rule::in(Campaign::CHANNELS)],
            'audience' => ['sometimes', 'array'],
            'template_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'variants' => ['sometimes', 'nullable', 'array', 'max:4'],
            'variants.*.key' => ['required', Rule::in(['A', 'B', 'C', 'D'])],
            'variants.*.template_id' => ['required', 'string', 'max:40'],
            'variants.*.weight' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'ab_config' => ['sometimes', 'nullable', 'array'],
            'ab_config.sample_pct' => ['sometimes', 'integer', 'min:5', 'max:50'],
            'ab_config.metric' => ['sometimes', Rule::in(['clicks', 'revenue'])],
            'ab_config.wait_hours' => ['sometimes', 'integer', 'min:1', 'max:72'],
            'holdout_pct' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'send_mode' => ['sometimes', Rule::in(Campaign::SEND_MODES)],
            'send_at' => ['sometimes', 'nullable', 'date'],
        ]);
        if (array_key_exists('audience', $data)) {
            try {
                $data['audience'] = AudienceQuery::normalize($data['audience']);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['audience' => $e->getMessage()]);
            }
        }
        $channel = $data['channel'] ?? $model->channel;
        if (array_key_exists('template_id', $data)) {
            $data['template_id'] = $this->templateKey($data['template_id'], $channel, 'template_id');
        }
        if (array_key_exists('variants', $data)) {
            $variants = [];
            foreach ((array) $data['variants'] as $i => $v) {
                $variants[] = ['key' => (string) $v['key'], 'template_id' => $this->templateKey((string) $v['template_id'], $channel, "variants.$i.template_id"), 'weight' => (int) ($v['weight'] ?? 1)];
            }
            if (count(array_unique(array_column($variants, 'key'))) !== count($variants)) {
                throw ValidationException::withMessages(['variants' => __('Cada variante necesita una letra distinta.')]);
            }
            $data['variants'] = count($variants) > 1 ? $variants : null;
            if (count($variants) === 1) {
                $data['template_id'] = $variants[0]['template_id'];
            }
        }
        if (array_key_exists('send_at', $data) && $data['send_at'] !== null) {
            $data['send_at'] = CarbonImmutable::parse((string) $data['send_at'])->utc();
        }
        if (array_key_exists('ab_config', $data) && is_array($data['ab_config'])) {
            $data['ab_config'] = ['sample_pct' => (int) ($data['ab_config']['sample_pct'] ?? 20), 'metric' => (string) ($data['ab_config']['metric'] ?? 'clicks'), 'wait_hours' => (int) ($data['ab_config']['wait_hours'] ?? 4)];
        }
        // Cambiar algo invalida la revisión anterior.
        $model->fill($data)->forceFill(['cost_estimate' => null, 'compliance_check' => null]);
        if ($model->status === Campaign::SCHEDULED) {
            $model->status = Campaign::DRAFT; // otra aprobación con la revisión nueva
        }
        $model->save();

        return response()->json(CampaignPresenter::make($model, true));
    }

    /** DELETE campaigns/{campaign} (solo borradores) */
    public function destroy(string $campaign, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($campaign);
        if ($model->status !== Campaign::DRAFT) {
            throw ValidationException::withMessages(['status' => __('Solo se pueden borrar las campañas en borrador.')]);
        }
        $audit->handle('campaign.deleted', $model, ['name' => $model->name], $this->current->id());
        $model->delete();

        return response()->json(['ok' => true]);
    }

    /** POST campaigns/{campaign}/duplicate */
    public function duplicate(Request $request, string $campaign): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($campaign);
        /** @var User $user */
        $user = $request->user();
        $copy = $model->replicate(['status', 'cost_estimate', 'compliance_check', 'stats', 'started_at', 'finished_at', 'winner_variant']);
        $copy->forceFill(['name' => mb_substr(__('Copia de :name', ['name' => $model->name]), 0, 160), 'status' => Campaign::DRAFT, 'created_by' => $user->id])->save();

        return response()->json(CampaignPresenter::make($copy, true), 201);
    }

    /**
     * POST campaigns/{campaign}/audience-count {audience?}: cuántos contactos tiene la audiencia y, por canal, cuántos
     * aceptaron recibir marketing por él (`by_channel`) con el costo aproximado de WhatsApp (AudienceReach).
     */
    public function audienceCount(Request $request, string $campaign, AudienceReach $reach): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = $this->find($campaign);
        try {
            $definition = $request->has('audience') ? AudienceQuery::normalize((array) $request->input('audience')) : ($model->audience ?? []);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['audience' => $e->getMessage()]);
        }
        $result = $reach->handle($this->current->get(), $definition);

        return response()->json(['count' => $result['total'], 'by_channel' => $result['channels'], 'whatsapp_cost' => $result['whatsapp_cost']]);
    }

    /**
     * GET campaigns/send-window?mode=&at=: a qué hora saldría la campaña según la ventana legal del país y, si se
     * mueve, por qué (SendWindowExplainer), para explicarlo en el paso «Cuándo» antes de guardar.
     */
    public function sendWindow(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate(['mode' => ['sometimes', Rule::in(Campaign::SEND_MODES)], 'at' => ['sometimes', 'nullable', 'date']]);
        $at = isset($data['at']) && is_string($data['at']) ? CarbonImmutable::parse($data['at']) : null;

        return response()->json(SendWindowExplainer::explain($this->current->get(), (string) ($data['mode'] ?? 'now'), $at));
    }

    /** POST campaigns/{campaign}/review: costo y revisión de cumplimiento. */
    public function review(string $campaign, ReviewCampaign $review): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($campaign);
        $this->assertReady($model);

        return response()->json($review->handle($this->current->get(), $model) + ['campaign' => CampaignPresenter::make($model->refresh(), true)]);
    }

    /** POST campaigns/{campaign}/schedule: aprueba y programa (o envía ya). */
    public function schedule(string $campaign, ReviewCampaign $review, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($campaign);
        if ($model->status !== Campaign::DRAFT) {
            throw ValidationException::withMessages(['status' => __('Solo se aprueba una campaña en borrador.')]);
        }
        $this->assertReady($model);
        if ($model->send_mode !== 'now' && ($model->send_at === null || ($model->send_mode === 'scheduled' && $model->send_at->isPast()))) {
            throw ValidationException::withMessages(['send_at' => __('Elige una fecha de envío en el futuro.')]);
        }
        // La aprobación siempre usa una revisión recién hecha.
        $result = $review->handle($this->current->get(), $model);
        if (! ($result['compliance']['channel_available'] ?? false)) {
            throw ValidationException::withMessages(['channel' => __('No hay una cuenta activa del canal: conéctala en Canales antes de enviar.')]);
        }
        $model->refresh()->forceFill(['status' => Campaign::SCHEDULED])->save();
        $audit->handle('campaign.approved', $model, [
            'send_mode' => $model->send_mode, 'send_at' => $model->send_at?->toIso8601String(),
            'will_send' => $result['compliance']['will_send'] ?? 0, 'cost_usd' => $result['cost']['total_usd'] ?? 0,
        ], $this->current->id());
        if ($model->send_mode === 'now') {
            app(CampaignSender::class)->materialize($this->current->get(), $model);
            SendCampaignBatch::dispatch($this->current->id(), $model->id);
        }

        return response()->json(CampaignPresenter::make($model->refresh(), true));
    }

    /** POST campaigns/{campaign}/pause | resume | cancel */
    public function pause(string $campaign, RecordAuditLog $audit): JsonResponse
    {
        return $this->transition($campaign, 'pause', $audit);
    }

    public function resume(string $campaign, RecordAuditLog $audit): JsonResponse
    {
        return $this->transition($campaign, 'resume', $audit);
    }

    public function cancel(string $campaign, RecordAuditLog $audit): JsonResponse
    {
        return $this->transition($campaign, 'cancel', $audit);
    }

    /** GET campaigns/{campaign}/report */
    public function report(string $campaign, CampaignReport $report): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = $this->find($campaign);

        return response()->json(['campaign' => CampaignPresenter::make($model, true), 'report' => $report->handle($this->current->get(), $model)]);
    }

    /** GET campaigns/options: segmentos y plantillas para el asistente. */
    public function options(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $segments = Segment::query()->orderBy('is_system')->orderBy('name')->get()
            ->map(fn (Segment $s) => ['id' => $s->publicId(), 'name' => $s->name, 'member_count' => $s->member_count, 'is_system' => $s->is_system, 'folder' => $s->folder])->values();
        $templates = DB::table('templates')->where('workspace_id', $ws->id)->where('status', '!=', 'archived')
            ->orderBy('name')->get(['id', 'name', 'channel', 'category', 'subject', 'status'])
            ->map(fn ($t) => ['id' => CampaignPresenter::template((string) $t->id), 'name' => (string) $t->name, 'channel' => (string) $t->channel, 'category' => (string) $t->category, 'subject' => $t->subject, 'status' => (string) $t->status])->values();
        $accounts = DB::table('channel_accounts')->where('workspace_id', $ws->id)->where('status', 'active')->pluck('channel')->unique()->values();

        return response()->json([
            'segments' => $segments,
            'templates' => $templates,
            'active_channels' => $accounts,
            'rules' => AudienceQuery::RULES,
            'workspace' => ['timezone' => $ws->timezone, 'country' => $ws->country, 'currency' => $ws->currency],
            'wa_rates' => ['effective_from' => WaRateCard::latestEffectiveFrom()?->toDateString(), 'stale' => WaRateCard::isStale(), 'source_url' => WaRateCard::SOURCE_URL],
        ]);
    }

    /** GET campaigns/calendar?year=&country= */
    public function calendar(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2020', 'max:2100'],
            'country' => ['sometimes', Rule::in(['CO', 'MX', 'VE'])],
        ]);
        $ws = $this->current->get();
        $year = (int) ($data['year'] ?? now()->setTimezone($ws->timezone ?: 'America/Bogota')->year);
        $country = (string) ($data['country'] ?? (in_array($ws->country, ['CO', 'MX', 'VE'], true) ? $ws->country : 'CO'));
        $from = (new CarbonImmutable(sprintf('%04d-01-01 00:00:00', $year), $ws->timezone ?: 'America/Bogota'))->utc();
        $campaigns = Campaign::query()->where(function ($q) use ($from) {
            $q->whereBetween('send_at', [$from, $from->addYear()])->orWhereBetween('started_at', [$from, $from->addYear()]);
        })->whereNotIn('status', [Campaign::CANCELLED])->get();

        return response()->json([
            'year' => $year,
            'country' => $country,
            'events' => CommercialCalendar::forYear($year, $country),
            'campaigns' => $campaigns->map(fn (Campaign $c) => CampaignPresenter::make($c))->values(),
        ]);
    }

    private function transition(string $campaign, string $action, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($campaign);
        $sender = app(CampaignSender::class);
        match ($action) {
            'pause' => in_array($model->status, [Campaign::SCHEDULED, Campaign::SENDING], true)
                ? $model->forceFill(['status' => Campaign::PAUSED])->save()
                : throw ValidationException::withMessages(['status' => __('Solo se pausa una campaña programada o en envío.')]),
            'resume' => $model->status === Campaign::PAUSED
                ? $model->forceFill(['status' => $model->started_at !== null ? Campaign::SENDING : Campaign::SCHEDULED])->save()
                : throw ValidationException::withMessages(['status' => __('La campaña no está pausada.')]),
            default => in_array($model->status, [Campaign::SCHEDULED, Campaign::SENDING, Campaign::PAUSED], true)
                ? $sender->cancel($model)
                : throw ValidationException::withMessages(['status' => __('Esta campaña ya no se puede cancelar.')]),
        };
        $audit->handle('campaign.'.($action === 'pause' ? 'paused' : ($action === 'resume' ? 'resumed' : 'cancelled')), $model, [], $this->current->id());

        return response()->json(CampaignPresenter::make($model->refresh(), true));
    }

    private function assertReady(Campaign $campaign): void
    {
        $errors = [];
        if (! AudienceQuery::isDefined($campaign->audience ?? [])) {
            $errors['audience'] = __('Elige al menos un segmento o «todos los contactos».');
        }
        foreach ($campaign->variantList() as $v) {
            if ($v['template_id'] === null) {
                $errors['template_id'] = __('Elige el contenido de la campaña.');
            }
        }
        if ($campaign->send_mode !== 'now' && $campaign->send_at === null) {
            $errors['send_at'] = __('Elige cuándo enviar.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function templateKey(?string $id, string $channel, string $field): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        $key = CampaignPresenter::templateKey($id);
        $ok = $key !== null && DB::table('templates')->where('workspace_id', $this->current->id())->where('id', $key)
            ->where('channel', $channel)->where('status', '!=', 'archived')->exists();
        if (! $ok) {
            throw ValidationException::withMessages([$field => __('La plantilla no existe o no es del canal de la campaña.')]);
        }

        return $key;
    }

    private function find(string $publicId): Campaign
    {
        return Campaign::findByPublicId($publicId) ?? abort(404);
    }
}
