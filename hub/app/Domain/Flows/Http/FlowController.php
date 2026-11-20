<?php

declare(strict_types=1);

namespace App\Domain\Flows\Http;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Actions\ChangeFlowStatus;
use App\Domain\Flows\Actions\CreateFlowFromLibrary;
use App\Domain\Flows\Actions\EnrollContacts;
use App\Domain\Flows\Actions\PublishFlow;
use App\Domain\Flows\Actions\SaveFlow;
use App\Domain\Flows\Actions\StartTestRun;
use App\Domain\Flows\Console\DateTriggersCommand;
use App\Domain\Flows\Graph\NodeTypes;
use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Flows\Library\LibraryAdapter;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowVersion;
use App\Domain\Flows\Queries\FlowAnalytics;
use App\Domain\Flows\Support\FlowRules;
use App\Domain\Flows\Support\InvalidFlow;
use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Segments\Models\Segment;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Support\LeadDefaults;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Ingest\Schema\SchemaRegistry;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** API de flujos de la SPA (modulos.md M09). Dentro de /api/w/{workspace}. */
final class FlowController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET flows */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $flows = Flow::query()->where('status', '!=', Flow::ARCHIVED)->orderByRaw("FIELD(status, 'active', 'paused', 'draft')")->orderBy('name')->get();
        $stats = DB::table('flow_runs')->where('workspace_id', $this->current->id())->whereIn('flow_id', $flows->pluck('id'))
            ->where(fn ($q) => $q->whereNull('trigger_key')->orWhere('trigger_key', 'not like', 'test:%'))
            ->where('started_at', '>=', now()->subDays(30))
            ->selectRaw("flow_id, COUNT(*) AS entered, SUM(status IN ('active', 'waiting')) AS live, SUM(exit_reason = 'goal_reached' AND is_holdout = 0) AS converted")
            ->groupBy('flow_id')->get()->keyBy('flow_id');
        $archived = Flow::query()->where('status', Flow::ARCHIVED)->count();

        return response()->json([
            'data' => $flows->map(fn (Flow $f) => FlowPresenter::summary($f, [
                'entered' => (int) ($stats->get($f->id)->entered ?? 0),
                'live' => (int) ($stats->get($f->id)->live ?? 0),
                'converted' => (int) ($stats->get($f->id)->converted ?? 0),
            ]))->values(),
            'archived_count' => $archived,
        ]);
    }

    /** POST flows */
    public function store(Request $request, SaveFlow $save): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();
        $result = $this->run(fn () => $save->handle(null, $data + ['trigger' => ['type' => 'manual'], 'graph' => SaveFlow::emptyGraph()], $user->id));

        return response()->json(FlowPresenter::detail($result['flow'], $result['issues'], true), 201);
    }

    /** GET flows/{flow} */
    public function show(string $flow, SaveFlow $save): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = $this->find($flow);

        return response()->json(FlowPresenter::detail($model, $save->issues($model), Gate::allows('ws.manageMarketing')));
    }

    /** PATCH flows/{flow}: ajustes y grafo del borrador */
    public function update(Request $request, string $flow, SaveFlow $save): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($flow);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'trigger' => ['sometimes', 'array'],
            'goal' => ['sometimes', 'nullable', 'array'],
            'reentry_policy' => ['sometimes', 'array'],
            'exclusions' => ['sometimes', 'array', 'max:20'],
            'holdout_pct' => ['sometimes', 'integer'],
            'graph' => ['sometimes', 'array'],
            'graph.nodes' => ['sometimes', 'array', 'max:120'],
            'graph.edges' => ['sometimes', 'array', 'max:300'],
        ]);
        $result = $this->run(fn () => $save->handle($model, $data));

        return response()->json(FlowPresenter::detail($result['flow'], $result['issues'], true));
    }

    /** DELETE flows/{flow}: borra un borrador que nunca corrió; si corrió, se archiva. */
    public function destroy(string $flow, ChangeFlowStatus $status, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($flow);
        if ($model->current_version_id === null && ! FlowRun::query()->where('flow_id', $model->id)->exists()) {
            FlowVersion::query()->where('flow_id', $model->id)->delete();
            $model->delete();
            $audit->handle('flow.deleted', $model, ['name' => $model->name], $model->workspace_id);

            return response()->json(['deleted' => true]);
        }
        $this->run(fn () => $status->handle($model, Flow::ARCHIVED));

        return response()->json(['deleted' => false, 'archived' => true]);
    }

    /** POST flows/{flow}/publish {migrate} */
    public function publish(Request $request, string $flow, PublishFlow $publish, SaveFlow $save): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($flow);
        /** @var User $user */
        $user = $request->user();
        $result = $this->run(fn () => $publish->handle($model, $user->id, $request->boolean('migrate')));

        return response()->json(FlowPresenter::detail($model->refresh(), $save->issues($model), true) + ['migrated' => $result['migrated']]);
    }

    /** POST flows/{flow}/{activate|pause|archive} */
    public function status(Request $request, string $flow, ChangeFlowStatus $status, SaveFlow $save): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($flow);
        $target = match ($request->route('action')) {
            'activate' => Flow::ACTIVE,
            'pause' => Flow::PAUSED,
            default => Flow::ARCHIVED,
        };
        $this->run(fn () => $status->handle($model, $target));

        return response()->json(FlowPresenter::detail($model->refresh(), $save->issues($model), true));
    }

    /** POST flows/{flow}/restore/{version}: copia una versión publicada al borrador */
    public function restore(string $flow, string $version, SaveFlow $save): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($flow);
        $source = FlowVersion::query()->where('flow_id', $model->id)->whereKey(PrefixedId::decode(FlowVersion::ID_PREFIX, $version) ?? '-')->firstOrFail();
        $save->saveDraft($model, (array) $source->graph);

        return response()->json(FlowPresenter::detail($model->refresh(), $save->issues($model), true));
    }

    /** GET flows/{flow}/versions/{version} */
    public function version(string $flow, string $version): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = $this->find($flow);
        $v = FlowVersion::query()->where('flow_id', $model->id)->whereKey(PrefixedId::decode(FlowVersion::ID_PREFIX, $version) ?? '-')->firstOrFail();

        return response()->json(['id' => $v->publicId(), 'version' => $v->version, 'published_at' => $v->published_at?->toIso8601String(), 'graph' => $v->graph]);
    }

    /** GET flows/{flow}/analytics?days=30 */
    public function analytics(Request $request, string $flow, FlowAnalytics $analytics): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = $this->find($flow);
        $days = (int) $request->query('days', '0');
        $from = $days > 0 ? CarbonImmutable::now()->subDays(min($days, 730)) : null;

        return response()->json($analytics->handle($model, $from));
    }

    /** GET flows/{flow}/runs?status=&page= */
    public function runs(Request $request, string $flow): JsonResponse
    {
        Gate::authorize('ws.viewAssignedContacts');
        $model = $this->find($flow);
        $status = (string) $request->query('status', '');
        $page = FlowRun::query()->where('flow_id', $model->id)
            ->where(fn ($q) => $q->whereNull('trigger_key')->orWhere('trigger_key', 'not like', 'test:%'))
            ->when(in_array($status, ['active', 'waiting', 'completed', 'exited', 'failed'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('started_at')->paginate(25);
        $contacts = Contact::query()->whereKey(collect($page->items())->pluck('contact_id')->unique()->all())->get()->keyBy('id');

        return response()->json([
            'data' => collect($page->items())->map(function (FlowRun $run) use ($contacts) {
                $contact = $contacts->get($run->contact_id);

                return [
                    'id' => $run->publicId(),
                    'contact' => $contact === null ? null : ['id' => $contact->publicId(), 'name' => $contact->displayName()],
                    'status' => $run->status,
                    'current_node_id' => $run->current_node_id,
                    'is_holdout' => $run->is_holdout,
                    'exit_reason' => $run->exit_reason,
                    'next_run_at' => $run->next_run_at?->toIso8601String(),
                    'started_at' => $run->started_at->toIso8601String(),
                    'ended_at' => $run->ended_at?->toIso8601String(),
                ];
            })->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** POST flows/{flow}/test {contact_id?, phone?} */
    public function test(Request $request, string $flow, StartTestRun $start): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($flow);
        $data = $request->validate(['contact_id' => ['nullable', 'string', 'max:40'], 'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{7,14}$/']]);
        $contact = isset($data['contact_id']) ? (Contact::findByPublicId($data['contact_id']) ?? abort(404)) : null;
        /** @var User $user */
        $user = $request->user();
        $result = $this->run(fn () => $start->handle($model, $user, $contact, $data['phone'] ?? null));

        return response()->json(['run_id' => $result['run']->publicId(), 'status' => $result['run']->status, 'steps' => $result['steps']]);
    }

    /** POST flows/{flow}/enroll {contact_ids[]} (entrada manual) */
    public function enroll(Request $request, string $flow, EnrollContacts $enroll): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($flow);
        $data = $request->validate(['contact_ids' => ['required', 'array', 'min:1', 'max:1000'], 'contact_ids.*' => ['string', 'max:40']]);
        if (! $model->isActive()) {
            throw (new InvalidFlow(['status' => __('Activa el flujo para inscribir contactos.')]))->toValidationException();
        }
        $ids = array_values(array_filter(array_map(fn ($id) => PrefixedId::decode(Contact::ID_PREFIX, (string) $id), $data['contact_ids'])));

        return response()->json(['entered' => $enroll->handle($model, $ids)]);
    }

    /** GET flows/library */
    public function library(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $workspaceId = $this->current->id();
        $existing = DB::table('templates')->where('workspace_id', $workspaceId)->whereNotNull('library_key')->where('status', '!=', 'archived')
            ->pluck('library_key')->flip();
        $created = Flow::query()->whereNotNull('template_key')->where('status', '!=', Flow::ARCHIVED)->pluck('id', 'template_key');

        $hasWhatsapp = LibraryAdapter::hasWhatsapp($workspaceId);

        return response()->json(['data' => array_map(function (array $f) use ($existing, $created, $workspaceId, $hasWhatsapp) {
            $f = LibraryAdapter::forWorkspace($f, $workspaceId, $hasWhatsapp);
            $keys = is_array($f['graph']) ? LibraryAdapter::templateKeys($f['graph']) : [];

            return [
                'key' => $f['key'], 'number' => $f['number'], 'name' => $f['name'], 'description' => $f['description'], 'phase' => $f['phase'],
                'available' => $f['graph'] !== null,
                'module' => $f['module'] ?? null,
                'trigger' => $f['trigger'],
                'templates' => $keys,
                // Las de la biblioteca de M08 se instalan al crear el flujo; faltan las demás (las aprobadas de WhatsApp).
                'missing_templates' => array_values(array_filter($keys, fn ($k) => ! $existing->has($k) && ! LibraryAdapter::installable($k))),
                'created_flow_id' => $created->has($f['key']) ? PrefixedId::encode(Flow::ID_PREFIX, (string) $created->get($f['key'])) : null,
                'steps' => count((array) ($f['graph']['nodes'] ?? [])),
            ];
        }, FlowLibrary::all()), 'meta' => ['whatsapp_connected' => $hasWhatsapp]]);
    }

    /** POST flows/library/{key} {activate} */
    public function fromLibrary(Request $request, string $key, CreateFlowFromLibrary $create): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        /** @var User $user */
        $user = $request->user();
        $result = $this->run(fn () => $create->handle($key, $request->boolean('activate'), $user->id));

        return response()->json(FlowPresenter::detail($result['flow'], $result['issues'], true) + ['activated' => $result['activated']], 201);
    }

    /** GET flows/options: lo que el constructor necesita para elegir */
    public function options(SchemaRegistry $schemas): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $workspaceId = $this->current->id();
        $events = array_values(array_filter($schemas->knownEvents(), fn (string $e) => ! str_starts_with($e, 'flow.') && ! str_starts_with($e, 'catalog.') && ! str_starts_with($e, 'site.')));
        sort($events);
        $nodes = [];
        foreach (NodeTypes::TYPES as $type => $meta) {
            if ($type === NodeTypes::TRIGGER) {
                continue;
            }
            $nodes[] = ['type' => $type, 'category' => $meta['category'], 'phase' => null, 'branches' => $meta['branches'] === 'dynamic' ? 'dynamic' : $meta['branches']];
        }

        return response()->json([
            'nodes' => $nodes,
            'events' => $events,
            'templates' => DB::table('templates')->where('workspace_id', $workspaceId)->where('status', '!=', 'archived')->orderBy('name')
                ->get(['id', 'name', 'channel', 'category', 'library_key', 'status'])
                ->map(fn ($t) => ['id' => TemplateRef::encode((string) $t->id), 'name' => $t->name, 'channel' => $t->channel, 'category' => $t->category, 'library_key' => $t->library_key, 'status' => $t->status])->values(),
            'segments' => Segment::query()->where('kind', Segment::KIND_DYNAMIC)->orderByDesc('is_system')->orderBy('name')->get(['id', 'name', 'is_system'])
                ->map(fn (Segment $s) => ['id' => $s->publicId(), 'name' => $s->name, 'is_system' => $s->is_system])->values(),
            'flows' => Flow::query()->where('status', '!=', Flow::ARCHIVED)->orderBy('name')->get(['id', 'name'])->map(fn (Flow $f) => ['id' => $f->publicId(), 'name' => $f->name])->values(),
            'date_attributes' => FlowRules::DATE_ATTRIBUTES,
            'threshold_metrics' => DateTriggersCommand::METRICS,
            // Para «Asignar a una persona», «Crear tarea» y «Mover etapa del lead».
            'team' => $this->current->get()->users()->orderBy('name')->get(['users.id', 'users.name'])->map(fn (User $u) => ['id' => $u->publicId(), 'name' => $u->name])->values(),
            'lead_stages' => self::leadStages(),
        ]);
    }

    /**
     * Las etapas de los pipelines de leads (sin repetir la clave), con el pipeline de cada una.
     *
     * @return list<array{key: string, label: string, kind: string, pipeline: string}>
     */
    private static function leadStages(): array
    {
        $pipelines = LeadPipeline::query()->orderByDesc('is_default')->orderBy('created_at')->get();
        $out = [];
        foreach ($pipelines->isEmpty() ? [null] : $pipelines->all() as $p) {
            foreach ($p !== null ? $p->stages : LeadDefaults::STAGES as $stage) {
                $out[$stage['key']] ??= ['key' => $stage['key'], 'label' => $p !== null ? $stage['label'] : __($stage['label']), 'kind' => $stage['kind'], 'pipeline' => $p !== null ? $p->name : __('Ventas')];
            }
        }

        return array_values($out);
    }

    private function find(string $publicId): Flow
    {
        return Flow::findByPublicId($publicId) ?? abort(404);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function run(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (InvalidFlow $e) {
            throw $e->toValidationException();
        }
    }
}
