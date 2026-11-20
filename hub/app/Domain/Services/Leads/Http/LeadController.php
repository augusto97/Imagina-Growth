<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Http;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Services\Leads\Actions\ChangeLeadStage;
use App\Domain\Services\Leads\Brief\LeadBrief;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Workspaces\Models\User;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Leads (M18): tablero por etapas, lista con filtros, detalle, cambios de etapa,
 * vendedor, valor, próximo paso y notas. Quien no ve todos los contactos (rol
 * agente) solo ve y mueve los leads que tiene asignados y no los reasigna.
 */
final class LeadController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $pipeline = $this->pipeline($request);
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'sort' => ['nullable', Rule::in(['recent', 'score', 'value', 'activity'])]]);
        $query = $this->filtered($request, $pipeline);
        match ($data['sort'] ?? 'recent') {
            'score' => $query->orderByDesc('score'),
            'value' => $query->orderByRaw('value_ws IS NULL')->orderByDesc('value_ws'),
            'activity' => $query->orderByDesc('last_activity_at'),
            default => $query->orderByDesc('created_at'),
        };
        $page = $query->orderByDesc('id')->paginate(25, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'pipeline' => self::pipelinePayload($pipeline),
            'data' => LeadPresenter::rows($this->current->get(), $page->items()),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Tablero: una columna por etapa con su conteo, su valor y los 50 leads más recientes. */
    public function board(Request $request): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $pipeline = $this->pipeline($request);
        $base = $this->filtered($request, $pipeline);
        $totals = (clone $base)->selectRaw('stage, COUNT(*) AS n, COALESCE(SUM(value_ws), 0) AS v')->groupBy('stage')->get()->keyBy('stage');
        $ws = $this->current->get();
        $columns = [];
        foreach ($pipeline->stages as $stage) {
            $leads = (clone $base)->where('stage', $stage['key'])->orderByDesc('last_activity_at')->orderByDesc('id')->limit(50)->get();
            $t = $totals->get($stage['key']);
            $columns[] = [
                'key' => $stage['key'], 'label' => $stage['label'], 'kind' => $stage['kind'],
                'count' => (int) ($t->n ?? 0),
                'value' => number_format((float) ($t->v ?? 0), 2, '.', ''),
                'leads' => LeadPresenter::rows($ws, $leads),
            ];
        }

        return response()->json(['pipeline' => self::pipelinePayload($pipeline), 'currency' => $ws->currency, 'columns' => $columns]);
    }

    public function show(string $lead): JsonResponse
    {
        Gate::authorize('ws.useLeads');

        return response()->json(LeadPresenter::detail($this->current->get(), $this->find($lead)));
    }

    public function store(Request $request, LeadIntake $intake): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $data = $request->validate([
            'contact_id' => ['required', 'string', 'max:40'],
            'service' => ['nullable', 'string', 'max:160'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ]);
        $contact = Contact::findByPublicId($data['contact_id']) ?? throw ValidationException::withMessages(['contact_id' => __('El contacto no existe.')]);
        $id = $intake->capture(new LeadIntakeInput(
            workspaceId: $this->current->id(), contactId: $contact->id, origin: 'manual', originRef: null,
            service: $data['service'] ?? null, value: isset($data['value']) ? (string) $data['value'] : null, source: ['channel' => 'manual'],
        ));
        if ($id === null) {
            abort(403, __('El módulo de servicios no está incluido en el plan.'));
        }
        $lead = Lead::query()->findOrFail($id);
        if (! Gate::allows('ws.viewAllContacts') && $lead->owner_id === null) {
            $lead->forceFill(['owner_id' => (string) $request->user()?->getAuthIdentifier()])->save();
        }

        return response()->json(LeadPresenter::detail($this->current->get(), $lead), 201);
    }

    public function update(Request $request, string $lead): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $l = $this->find($lead);
        $data = $request->validate([
            'owner_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'service' => ['sometimes', 'nullable', 'string', 'max:160'],
            'next_step' => ['sometimes', 'nullable', 'string', 'max:255'],
            'next_step_at' => ['sometimes', 'nullable', 'date'],
        ]);
        $actor = (string) $request->user()?->getAuthIdentifier();
        if (array_key_exists('owner_id', $data)) {
            if (! Gate::allows('ws.viewAllContacts')) {
                abort(403, __('Solo un dueño, administrador o mercadeo puede reasignar leads.'));
            }
            $owner = $data['owner_id'] !== null ? $this->member($data['owner_id']) : null;
            if ($owner?->id !== $l->owner_id) {
                LeadActivity::log($l, 'owner', $actor, ['from' => $l->owner_id !== null ? 'usr_'.$l->owner_id : null, 'to' => $owner?->publicId(), 'to_name' => $owner?->name]);
                $l->owner_id = $owner?->id;
            }
        }
        if (array_key_exists('value', $data)) {
            $value = $data['value'] !== null ? number_format((float) $data['value'], 2, '.', '') : null;
            if ($value !== $l->value_ws) {
                LeadActivity::log($l, 'value', $actor, ['value' => $value]);
                $l->value_ws = $value;
            }
        }
        foreach (['service', 'next_step'] as $field) {
            if (array_key_exists($field, $data)) {
                $l->{$field} = $data[$field] !== null && trim((string) $data[$field]) !== '' ? trim((string) $data[$field]) : null;
            }
        }
        if (array_key_exists('next_step_at', $data)) {
            $l->next_step_at = $data['next_step_at'] !== null ? now()->parse((string) $data['next_step_at']) : null;
        }
        $l->save();

        return response()->json(LeadPresenter::detail($this->current->get(), $l));
    }

    public function stage(Request $request, string $lead, ChangeLeadStage $change): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $l = $this->find($lead);
        $data = $request->validate([
            'stage' => ['required', 'string', 'max:32'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lost_reason' => ['nullable', 'string', 'max:120'],
        ]);
        $l = DB::transaction(fn () => $change->handle(
            $this->current->get(), $l, $data['stage'], 'user', (string) $request->user()?->getAuthIdentifier(),
            isset($data['value']) ? (string) $data['value'] : null, $data['lost_reason'] ?? null,
        ));

        return response()->json(LeadPresenter::detail($this->current->get(), $l));
    }

    /**
     * Una persona corrige la ficha que llenó la IA. Lo que edita queda como suyo y la IA ya
     * no lo cambia; vaciar un campo lo deja vacío a propósito.
     */
    public function brief(Request $request, string $lead): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $l = $this->find($lead);
        $rules = [];
        foreach (LeadBrief::TEXT_FIELDS as $field => $max) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:'.$max];
        }
        $data = $request->validate($rules + ['temperature' => ['sometimes', 'nullable', Rule::in(LeadBrief::TEMPERATURES)]]);
        $userId = (string) $request->user()?->getAuthIdentifier();
        /** @var array<string, string|null> $data */
        [$brief, $changed] = LeadBrief::edit($l->brief, $data, now()->toIso8601String(), $userId);
        if ($changed !== []) {
            $l->forceFill(['brief' => $brief, 'brief_updated_at' => now(), 'last_activity_at' => now()])->save();
            LeadActivity::log($l, 'brief', $userId, ['fields' => $changed]);
        }

        return response()->json(LeadPresenter::detail($this->current->get(), $l));
    }

    public function note(Request $request, string $lead): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $l = $this->find($lead);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        // La nota puede traer datos personales: se guarda cifrada.
        LeadActivity::log($l, 'note', (string) $request->user()?->getAuthIdentifier(), ['body' => encrypt(trim($data['body']))]);
        $l->forceFill(['last_activity_at' => now()])->save();

        return response()->json(LeadPresenter::detail($this->current->get(), $l), 201);
    }

    /** Personas del equipo para filtrar y asignar. */
    public function team(): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $users = $this->current->get()->users()->orderBy('name')->get(['users.id', 'users.name']);

        return response()->json(['data' => $users->map(fn (User $u) => ['id' => $u->publicId(), 'name' => $u->name, 'role' => (string) ($u->getRelation('pivot')?->getAttribute('role') ?? '')])->values()->all()]);
    }

    /** @return Builder<Lead> */
    private function filtered(Request $request, LeadPipeline $pipeline): Builder
    {
        $f = $request->validate([
            'stage' => ['nullable', 'string', 'max:32'],
            'owner' => ['nullable', 'string', 'max:40'],
            'temperature' => ['nullable', Rule::in(['cold', 'warm', 'hot'])],
            'source' => ['nullable', 'string', 'max:32'],
            'service' => ['nullable', 'string', 'max:160'],
            'origin' => ['nullable', Rule::in(Lead::ORIGINS)],
            'status' => ['nullable', Rule::in(['open', 'won', 'lost', 'all'])],
            'contact' => ['nullable', 'string', 'max:40'],
        ]);
        $query = Lead::query()->where(fn (Builder $q) => $pipeline->is_default
            ? $q->where('pipeline_id', $pipeline->id)->orWhereNull('pipeline_id')
            : $q->where('pipeline_id', $pipeline->id));
        if (! Gate::allows('ws.viewAllContacts')) {
            $query->where('owner_id', (string) request()->user()?->getAuthIdentifier());
        }
        $owner = $f['owner'] ?? null;
        if ($owner === 'me') {
            $query->where('owner_id', (string) request()->user()?->getAuthIdentifier());
        } elseif ($owner === 'none') {
            $query->whereNull('owner_id');
        } elseif (is_string($owner) && $owner !== '') {
            $query->where('owner_id', PrefixedId::decode(User::ID_PREFIX, $owner) ?? '-');
        }
        foreach (['stage', 'temperature', 'origin'] as $col) {
            if (isset($f[$col]) && $f[$col] !== '') {
                $query->where($col, $f[$col]);
            }
        }
        if (isset($f['contact']) && $f['contact'] !== '') {
            $query->where('contact_id', PrefixedId::decode(Contact::ID_PREFIX, $f['contact']) ?? '-');
        }
        if (isset($f['source']) && $f['source'] !== '') {
            $query->where('source->channel', $f['source']);
        }
        if (isset($f['service']) && trim($f['service']) !== '') {
            $query->where('service', 'like', '%'.addcslashes(trim($f['service']), '%_\\').'%');
        }
        match ($f['status'] ?? 'all') {
            'open' => $query->whereNull('won_at')->whereNull('lost_at'),
            'won' => $query->whereNotNull('won_at'),
            'lost' => $query->whereNotNull('lost_at'),
            default => null,
        };

        return $query;
    }

    private function pipeline(Request $request): LeadPipeline
    {
        $id = $request->query('pipeline');
        if (is_string($id) && $id !== '') {
            return LeadPipeline::findByPublicId($id) ?? abort(404);
        }

        return Pipelines::default($this->current->id());
    }

    /** @return array<string, mixed> */
    public static function pipelinePayload(LeadPipeline $p): array
    {
        return ['id' => $p->publicId(), 'name' => $p->name, 'is_default' => $p->is_default, 'stages' => $p->stages];
    }

    private function find(string $publicId): Lead
    {
        $lead = Lead::findByPublicId($publicId) ?? abort(404);
        if (! Gate::allows('ws.viewAllContacts') && $lead->owner_id !== (string) request()->user()?->getAuthIdentifier()) {
            abort(404);
        }

        return $lead;
    }

    /** ¿Quien pide puede abrir este lead? (rol agente: solo los suyos) */
    public static function canOpen(Lead $lead, string $userId): bool
    {
        return Gate::allows('ws.viewAllContacts') || $lead->owner_id === $userId;
    }

    private function member(string $publicId): User
    {
        $id = PrefixedId::decode(User::ID_PREFIX, $publicId);
        $user = $id !== null ? $this->current->get()->users()->where('users.id', $id)->first() : null;
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['owner_id' => __('Esa persona no es del equipo.')]);
        }

        return $user;
    }
}
