<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Http;

use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Quotes\Support\QuoteCapture;
use App\Domain\Quotes\Support\QuoteEngine;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Constructor del cotizador (M18). Ver las calculadoras y sus cotizaciones:
 * `useLeads`; crearlas y cambiarlas: `manageServices`.
 */
final class QuoteController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET quotes/calculators */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $since = now()->subDays(30);
        $stats = Quote::query()->where('created_at', '>=', $since)->groupBy('calculator_id')
            ->get(['calculator_id', DB::raw('COUNT(*) as quotes'), DB::raw('COUNT(contact_id) as contacts'), DB::raw('COUNT(lead_id) as leads'),
                DB::raw('AVG((estimate_min + estimate_max) / 2) as avg_mid')])->keyBy('calculator_id');

        return response()->json([
            'can_manage' => Gate::allows('ws.manageServices'),
            'currency' => $this->current->get()->currency ?: 'COP',
            'sites' => Site::query()->orderBy('created_at')->get()->map(fn (Site $s) => ['id' => $s->publicId(), 'name' => $s->name ?? $s->url ?? $s->publicId(), 'public_key' => $s->public_key])->values(),
            'data' => QuoteCalculator::query()->orderByDesc('updated_at')->get()->map(function (QuoteCalculator $c) use ($stats) {
                $s = $stats->get($c->id);

                return self::calculator($c) + ['stats' => [
                    'quotes_30d' => (int) ($s->quotes ?? 0), 'contacts_30d' => (int) ($s->contacts ?? 0), 'leads_30d' => (int) ($s->leads ?? 0),
                    'avg_mid' => isset($s->avg_mid) ? QuoteEngine::money((float) $s->avg_mid) : null,
                ]];
            })->values(),
        ]);
    }

    /** GET quotes/calculators/{calculator} */
    public function show(string $calculator): JsonResponse
    {
        Gate::authorize('ws.useLeads');

        return response()->json(self::calculator($this->find($calculator)));
    }

    /** POST quotes/calculators · PATCH quotes/calculators/{calculator} */
    public function save(Request $request, ?string $calculator = null): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $model = $calculator !== null ? $this->find($calculator) : new QuoteCalculator;
        $data = $request->validate([
            'name' => [$model->exists ? 'sometimes' : 'required', 'string', 'max:160'],
            'kind' => [$model->exists ? 'sometimes' : 'required', Rule::in(QuoteCalculator::KINDS)],
            'site_id' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(QuoteCalculator::STATUSES)],
            'definition' => [$model->exists ? 'sometimes' : 'required', 'array'],
            'capture' => ['sometimes', 'array'],
        ]);
        if (array_key_exists('site_id', $data)) {
            $data['site_id'] = $data['site_id'] !== null ? (Site::findByPublicId((string) $data['site_id'])->id
                ?? throw ValidationException::withMessages(['site_id' => __('Ese sitio no es de este espacio de trabajo.')])) : null;
        }
        $kind = (string) ($data['kind'] ?? $model->kind ?? 'service');
        if (isset($data['definition']) || isset($data['kind'])) {
            $data['definition'] = QuoteEngine::normalize((array) ($data['definition'] ?? $model->definition ?? []), $kind);
        }
        if (isset($data['capture'])) {
            $data['capture'] = QuoteCapture::for((array) $data['capture']);
        }
        if (! $model->exists) {
            $data += ['workspace_id' => $this->current->id(), 'status' => 'draft', 'capture' => QuoteCapture::defaults()];
        }
        $model->fill($data)->save();
        $this->audit->handle($calculator === null ? 'quote_calculator.created' : 'quote_calculator.updated', $model, ['fields' => array_keys($data)], $this->current->id());

        return response()->json(self::calculator($model->refresh()), $calculator === null ? 201 : 200);
    }

    /** DELETE quotes/calculators/{calculator} — con cotizaciones, se pausa */
    public function destroy(string $calculator): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $model = $this->find($calculator);
        if (Quote::query()->where('calculator_id', $model->id)->exists()) {
            $model->forceFill(['status' => 'paused'])->save();
        } else {
            $model->delete();
        }
        $this->audit->handle('quote_calculator.deleted', $model, [], $this->current->id());

        return response()->json(null, 204);
    }

    /** POST quotes/calculators/{calculator}/preview {answers, definition?, kind?} — prueba el cálculo sin guardar */
    public function preview(Request $request, string $calculator): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $model = $this->find($calculator);
        $data = $request->validate(['answers' => ['present', 'array'], 'definition' => ['sometimes', 'array'], 'kind' => ['sometimes', Rule::in(QuoteCalculator::KINDS)]]);
        $probe = $model->replicate();
        if (isset($data['definition'])) {
            $probe->kind = (string) ($data['kind'] ?? $model->kind);
            $probe->definition = QuoteEngine::normalize((array) $data['definition'], $probe->kind);
        }

        return response()->json(QuoteEngine::estimate($probe, (array) $data['answers']));
    }

    /** GET quotes/requests?calculator=&page= */
    public function requests(Request $request): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $query = Quote::query()->orderByDesc('created_at');
        if (is_string($request->query('calculator'))) {
            $query->where('calculator_id', QuoteCalculator::findByPublicId($request->query('calculator'))->id ?? '-');
        }
        if (! Gate::allows('ws.viewAllContacts')) {
            $visible = Contact::query();
            ContactAccess::scope($visible);
            $query->whereIn('contact_id', $visible->select('contacts.id'));
        }
        $page = $query->paginate(25);
        $contacts = Contact::query()->whereIn('id', collect($page->items())->pluck('contact_id')->filter()->unique())->get()->keyBy('id');
        $calculators = QuoteCalculator::query()->pluck('name', 'id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Quote $q) => [
                'id' => $q->publicId(), 'created_at' => $q->created_at->toIso8601ZuluString(),
                'calculator' => (string) ($calculators[$q->calculator_id] ?? '—'),
                'estimate_min' => $q->estimate_min !== null ? (string) $q->estimate_min : null, 'estimate_max' => $q->estimate_max !== null ? (string) $q->estimate_max : null,
                'currency' => $q->currency, 'answers' => $q->answers,
                'lead_id' => $q->lead_id !== null ? PrefixedId::encode('ld', $q->lead_id) : null,
                'contact' => ($c = $contacts->get((string) $q->contact_id)) !== null ? [
                    'id' => $c->publicId(), 'name' => trim($c->first_name.' '.$c->last_name) ?: null,
                    'email' => ContactAccess::email($c->email), 'phone' => ContactAccess::phone($c->phone),
                ] : null,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private function find(string $id): QuoteCalculator
    {
        return QuoteCalculator::findByPublicId($id) ?? abort(404);
    }

    /** @return array<string, mixed> */
    public static function calculator(QuoteCalculator $c): array
    {
        return [
            'id' => $c->publicId(), 'name' => $c->name, 'kind' => $c->kind, 'status' => $c->status,
            'site_id' => $c->site_id !== null ? PrefixedId::encode('st', $c->site_id) : null,
            'definition' => $c->definition, 'capture' => QuoteCapture::for($c->capture),
            'shortcode' => '[igr_quote id="'.$c->publicId().'"]',
            'updated_at' => $c->updated_at?->toIso8601ZuluString(),
        ];
    }
}
