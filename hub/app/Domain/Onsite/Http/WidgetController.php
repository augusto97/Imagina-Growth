<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Http;

use App\Domain\Consent\Actions\ConsentTexts;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Models\Quiz;
use App\Domain\Onsite\Models\WidgetCapture;
use App\Domain\Onsite\Queries\WidgetStats;
use App\Domain\Onsite\Support\WidgetSchema;
use App\Domain\Onsite\Support\WidgetValidator;
use App\Domain\Segments\Models\Segment;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Ids\PrefixedId;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API de «En el sitio» (modulos.md M12) para la SPA. Ver: viewDashboards; crear,
 * editar, activar, pausar, archivar o borrar: manageMarketing (queda en la auditoría).
 */
final class WidgetController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET onsite/widgets?status=&days=30 */
    public function index(Request $request, WidgetStats $stats): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $status = $request->query('status');
        $widgets = OnsiteWidget::query()
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderByRaw("FIELD(status, 'active', 'paused', 'draft', 'archived')")->orderBy('priority')->orderByDesc('updated_at')
            ->limit(300)->get();
        [$from, $to] = $this->range($request);
        $summary = $stats->summary($this->current->get(), array_values($widgets->pluck('id')->map(fn ($id) => (string) $id)->all()), $from, $to);

        return response()->json([
            'data' => $widgets->map(fn (OnsiteWidget $w) => WidgetPresenter::make($w) + ['stats' => $summary[$w->id] ?? null])->values(),
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
        ]);
    }

    /** GET onsite/options: lo que necesita el constructor. */
    public function options(ConsentTexts $texts): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $sites = Site::query()->orderBy('created_at')->get()
            ->map(fn (Site $s) => ['id' => $s->publicId(), 'name' => (string) ($s->getAttribute('name') ?: $s->getAttribute('host')), 'url' => $s->getAttribute('url'), 'status' => $s->getAttribute('status')])->values();
        $segments = Segment::query()->orderBy('is_system')->orderBy('name')->get()
            ->map(fn (Segment $s) => ['id' => $s->publicId(), 'name' => $s->name, 'member_count' => $s->member_count])->values();
        $templates = DB::table('templates')->where('workspace_id', $ws->id)->where('channel', 'email')->where('status', '!=', 'archived')
            ->orderBy('name')->get(['id', 'name'])->map(fn ($t) => ['id' => 'tpl_'.$t->id, 'name' => (string) $t->name])->values();
        $categories = DB::table('product_categories')->where('workspace_id', $ws->id)->whereNull('deleted_at')
            ->orderBy('name')->limit(500)->get(['external_id', 'name'])->map(fn ($c) => ['id' => (string) $c->external_id, 'name' => (string) $c->name])->values();
        $brands = DB::table('products')->where('workspace_id', $ws->id)->whereNotNull('brand')->where('brand', '!=', '')
            ->distinct()->orderBy('brand')->limit(200)->pluck('brand')->values();

        return response()->json([
            'kinds' => OnsiteWidget::KINDS,
            'page_types' => WidgetSchema::PAGE_TYPES,
            'devices' => WidgetSchema::DEVICES,
            'triggers' => WidgetSchema::TRIGGERS,
            'sites' => $sites,
            'segments' => $segments,
            'consent_texts' => $texts->current($ws),
            'email_templates' => $templates,
            'categories' => $categories,
            'brands' => $brands,
            'workspace' => ['currency' => $ws->currency, 'country' => $ws->country, 'timezone' => $ws->timezone],
        ]);
    }

    /** POST onsite/widgets {name, kind, site_id?} */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(OnsiteWidget::KINDS)],
            'site_id' => ['nullable', 'string'],
        ]);
        $siteId = null;
        if (! empty($data['site_id'])) {
            $site = Site::findByPublicId((string) $data['site_id']);
            if ($site === null) {
                throw ValidationException::withMessages(['site_id' => __('El sitio no existe en este espacio de trabajo.')]);
            }
            $siteId = $site->id;
        }
        /** @var User $user */
        $user = $request->user();
        $defaults = WidgetSchema::defaults((string) $data['kind']);
        $widget = DB::transaction(function () use ($data, $siteId, $defaults, $user): OnsiteWidget {
            $widget = OnsiteWidget::query()->create([
                'workspace_id' => $this->current->id(),
                'site_id' => $siteId,
                'name' => trim((string) $data['name']),
                'kind' => $data['kind'],
                'targeting' => $defaults['targeting'],
                'design' => $defaults['design'],
                'content' => $defaults['content'],
                'variants' => [['key' => 'a', 'weight' => 100, 'design' => $defaults['design'], 'content' => $defaults['content']]],
                'consent_config' => $defaults['consent_config'],
                'coupon_config' => $defaults['coupon_config'],
                'created_by' => $user->id,
            ]);
            if (is_array($defaults['quiz'])) {
                Quiz::query()->create(['widget_id' => $widget->id] + $defaults['quiz']);
            }

            return $widget;
        });
        $this->audit->handle('onsite_widget.created', $widget, ['name' => $widget->name, 'kind' => $widget->kind], $this->current->id());

        return response()->json(WidgetPresenter::make($widget->load('quiz'), true), 201);
    }

    /** GET onsite/widgets/{widget} */
    public function show(string $widget): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(WidgetPresenter::make($this->find($widget), true));
    }

    /** PATCH onsite/widgets/{widget}: name, site_id, priority, targeting, variants, consent_config, coupon_config, quiz, starts_at, ends_at, ab_auto_winner */
    public function update(Request $request, string $widget, WidgetValidator $validator): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($widget);
        if ($model->status === 'archived') {
            throw ValidationException::withMessages(['status' => __('El widget está archivado. Duplícalo para volver a usarlo.')]);
        }
        $attributes = $validator->validate($model, $request->all());
        $quiz = $attributes['quiz'] ?? null;
        unset($attributes['quiz']);
        if ($request->has('ab_auto_winner')) {
            $attributes['stats'] = array_merge((array) ($model->stats ?? []), ['auto_winner' => $request->boolean('ab_auto_winner')]);
        }

        DB::transaction(function () use ($model, $attributes, $quiz, $validator): void {
            $model->forceFill($attributes)->save();
            if (is_array($quiz)) {
                Quiz::query()->updateOrCreate(['widget_id' => $model->id], $quiz);
                $model->load('quiz');
            }
            // Un widget activo no puede quedar incompleto en el sitio.
            if ($model->status === 'active') {
                $validator->assertActivatable($model);
            }
        });
        $this->audit->handle('onsite_widget.updated', $model, ['fields' => array_keys($attributes)], $this->current->id());

        return response()->json(WidgetPresenter::make($model->refresh()->load('quiz'), true));
    }

    /** DELETE onsite/widgets/{widget}: solo sin capturas (si no, se archiva). */
    public function destroy(string $widget): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($widget);
        if (WidgetCapture::query()->where('widget_id', $model->id)->exists()) {
            return response()->json(['message' => __('Este widget ya capturó contactos: archívalo para conservar sus estadísticas.')], 409);
        }
        DB::transaction(function () use ($model): void {
            Quiz::query()->where('widget_id', $model->id)->delete();
            $model->delete();
        });
        $this->audit->handle('onsite_widget.deleted', $model, ['name' => $model->name], $this->current->id());

        return response()->json(['ok' => true]);
    }

    /** POST onsite/widgets/{widget}/duplicate */
    public function duplicate(Request $request, string $widget): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $source = $this->find($widget);
        /** @var User $user */
        $user = $request->user();
        $copy = DB::transaction(function () use ($source, $user): OnsiteWidget {
            $copy = $source->replicate(['status', 'stats', 'created_by']);
            $copy->forceFill([
                'name' => mb_substr(__(':name (copia)', ['name' => $source->name]), 0, 160),
                'status' => 'draft',
                'stats' => isset($source->stats['auto_winner']) ? ['auto_winner' => $source->stats['auto_winner']] : null,
                'created_by' => $user->id,
            ])->save();
            if ($source->quiz !== null) {
                Quiz::query()->create(['widget_id' => $copy->id, 'questions' => $source->quiz->questions, 'outcomes' => $source->quiz->outcomes]);
            }

            return $copy;
        });
        $this->audit->handle('onsite_widget.created', $copy, ['name' => $copy->name, 'kind' => $copy->kind, 'from' => $source->publicId()], $this->current->id());

        return response()->json(WidgetPresenter::make($copy->load('quiz'), true), 201);
    }

    /** POST onsite/widgets/{widget}/activate */
    public function activate(string $widget, WidgetValidator $validator): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($widget);
        $validator->assertActivatable($model);

        return $this->transition($model, 'active', 'onsite_widget.activated');
    }

    /** POST onsite/widgets/{widget}/pause */
    public function pause(string $widget): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');

        return $this->transition($this->find($widget), 'paused', 'onsite_widget.paused');
    }

    /** POST onsite/widgets/{widget}/archive */
    public function archive(string $widget): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');

        return $this->transition($this->find($widget), 'archived', 'onsite_widget.archived');
    }

    /** GET onsite/widgets/{widget}/stats?days=30 | from=&to= */
    public function stats(Request $request, string $widget, WidgetStats $stats): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = $this->find($widget);
        [$from, $to] = $this->range($request);

        return response()->json($stats->detail($this->current->get(), $model, $from, $to) + [
            'currency' => $this->current->get()->currency,
            'winner' => $model->stats['winner'] ?? null,
        ]);
    }

    /** GET onsite/widgets/{widget}/captures: las últimas 50 capturas (sin datos de contacto para quien no puede verlos). */
    public function captures(string $widget): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = $this->find($widget);
        $rows = WidgetCapture::query()->where('widget_id', $model->id)->orderByDesc('captured_at')->limit(50)->get();
        $canSee = Gate::allows('ws.viewAllContacts');
        $contacts = $canSee ? Contact::query()->whereIn('id', $rows->pluck('contact_id')->all())->get()->keyBy('id') : collect();
        $coupons = DB::table('coupons_issued')->where('workspace_id', $this->current->id())->whereIn('id', $rows->pluck('coupon_id')->filter()->all())->pluck('code', 'id');

        return response()->json(['data' => $rows->map(function (WidgetCapture $c) use ($contacts, $coupons, $canSee) {
            $contact = $contacts[$c->contact_id] ?? null;
            $name = $contact !== null ? trim((string) $contact->getAttribute('first_name').' '.(string) $contact->getAttribute('last_name')) : '';

            return [
                'contact' => $canSee ? [
                    'id' => PrefixedId::encode(Contact::ID_PREFIX, $c->contact_id),
                    'name' => $name !== '' ? $name : null,
                    'email' => $contact?->getAttribute('email') !== null ? Pii::maskEmail((string) $contact->getAttribute('email')) : null,
                ] : null,
                'variant' => $c->variant,
                'was_known' => $c->was_known,
                'coupon' => $c->coupon_id !== null ? ($coupons[$c->coupon_id] ?? null) : null,
                'prize' => $c->prize,
                'answers' => $c->answers,
                'captured_at' => $c->captured_at->toIso8601String(),
            ];
        })->values()]);
    }

    private function transition(OnsiteWidget $model, string $status, string $action): JsonResponse
    {
        $from = $model->status;
        $model->forceFill(['status' => $status])->save();
        $this->audit->handle($action, $model, ['from' => $from, 'to' => $status], $this->current->id());

        return response()->json(WidgetPresenter::make($model->load('quiz'), true));
    }

    private function find(string $publicId): OnsiteWidget
    {
        $widget = OnsiteWidget::findByPublicId($publicId);
        abort_if($widget === null, 404);

        return $widget->load('quiz');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $tz = $this->current->get()->timezone ?: 'America/Bogota';
        try {
            if (is_string($request->query('from')) && is_string($request->query('to'))) {
                $from = CarbonImmutable::parse((string) $request->query('from'), $tz)->startOfDay();
                $to = CarbonImmutable::parse((string) $request->query('to'), $tz)->endOfDay();
                if ($to->greaterThan($from) && $from->diffInDays($to) <= 400) {
                    return [$from->utc(), $to->utc()];
                }
            }
        } catch (\Throwable) {
            // rango inválido: se usa el de por defecto
        }
        $days = max(1, min(365, (int) $request->query('days', '30')));
        $now = CarbonImmutable::now($tz);

        return [$now->subDays($days - 1)->startOfDay()->utc(), $now->endOfDay()->utc()];
    }
}
