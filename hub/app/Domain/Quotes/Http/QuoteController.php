<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Http;

use App\Domain\Capture\Http\LandingPageController;
use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Copilot\Http\CopilotController;
use App\Domain\Intelligence\Ai\Copilot\Support\CopilotFailed;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Domain\Quotes\Http\Public\PublicQuoteController;
use App\Domain\Quotes\Http\Public\QuotePageController;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Quotes\Support\QuoteAssistant;
use App\Domain\Quotes\Support\QuoteCapture;
use App\Domain\Quotes\Support\QuoteEngine;
use App\Domain\Quotes\Support\QuotePage;
use App\Domain\Quotes\Support\QuoteTemplates;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Constructor del cotizador (M18). Ver las calculadoras y sus cotizaciones:
 * `useLeads`; crearlas y cambiarlas: `manageServices`. El asistente usa las
 * plantillas por oficio (`templates`), la propuesta con IA (`assistant`, nunca
 * guarda), los ejemplos de cálculo sin guardar (`previewDraft`) y la vista previa
 * de la página pública (`pagePreview`, la misma vista que ve el cliente).
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
            'ai' => app(AiGateway::class)->available($this->current->id()),
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

    /** GET quotes/templates — plantillas por oficio con precios de ejemplo en la moneda del espacio de trabajo */
    public function templates(): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $currency = (string) ($this->current->get()->currency ?: 'COP');

        return response()->json(['currency' => $currency, 'data' => array_map(fn (array $t) => array_replace($t, [
            'definition' => QuoteEngine::normalize($t['definition'], $t['kind']),
        ]), QuoteTemplates::all($currency))]);
    }

    /**
     * POST quotes/preview {kind, definition, scenarios: [respuestas…]} — el cálculo de hasta 4 ejemplos con una
     * definición sin guardar (el asistente: «si alguien pide 40 m² con pintura premium, ve…»).
     */
    public function previewDraft(Request $request): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $data = $request->validate(['kind' => ['required', Rule::in(QuoteCalculator::KINDS)], 'definition' => ['required', 'array'],
            'scenarios' => ['required', 'array', 'min:1', 'max:4'], 'scenarios.*' => ['array']]);
        $probe = new QuoteCalculator(['kind' => $data['kind']]);
        $probe->definition = QuoteEngine::normalize((array) $data['definition'], (string) $data['kind'], draft: true);

        return response()->json(['data' => array_map(function (array $answers) use ($probe): array {
            try {
                return ['ok' => true] + QuoteEngine::estimate($probe, $answers);
            } catch (ValidationException $e) {
                return ['ok' => false, 'message' => (string) (array_values($e->errors())[0][0] ?? __('Revisa los datos.'))];
            }
        }, array_values((array) $data['scenarios']))]);
    }

    /** POST quotes/assistant {description} — la IA propone el cotizador; no guarda nada */
    public function assistant(Request $request, QuoteAssistant $assistant): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $data = $request->validate(['description' => ['required', 'string', 'min:15', 'max:2000']]);
        try {
            $proposal = $assistant->propose($this->current->get(), (string) $data['description']);
        } catch (AiUnavailable $e) {
            return response()->json(['message' => CopilotController::unavailableMessage($e), 'reason' => $e->reason], $e->reason === 'invalid' ? 422 : 503);
        } catch (CopilotFailed $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }

        return response()->json($proposal);
    }

    /**
     * POST quotes/page-preview {name, kind, definition, capture} — guarda el borrador 30 minutos y devuelve la URL de
     * la vista previa de la página pública (para el iframe del panel; misma sesión, mismo origen).
     */
    public function pagePreviewStore(Request $request): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'kind' => ['required', Rule::in(QuoteCalculator::KINDS)],
            'definition' => ['required', 'array'], 'capture' => ['sometimes', 'array']]);
        $draft = [
            'name' => (string) $data['name'], 'kind' => (string) $data['kind'],
            'definition' => QuoteEngine::normalize((array) $data['definition'], (string) $data['kind'], draft: true),
            'capture' => QuoteCapture::for((array) ($data['capture'] ?? [])),
        ];
        $token = Str::random(32);
        Cache::put(self::previewKey($this->current->id(), $token), $draft, now()->addMinutes(30));

        return response()->json(['url' => '/api/w/'.$this->current->get()->publicId().'/quotes/page-preview/'.$token]);
    }

    /** GET quotes/page-preview/{token} — la página pública con el borrador, sin guardar nada */
    public function pagePreview(string $token, PublicWidgetConfig $widgets): Response
    {
        Gate::authorize('ws.useLeads');
        $model = $this->previewModel($token);
        $workspace = $this->current->get();

        return QuotePage::view('quotes.page', $workspace, QuotePageController::pageData($workspace, $model, $widgets, [], []) + [
            'action' => '/api/w/'.$workspace->publicId().'/quotes/page-preview/'.$token.'/result', 'method' => 'get', 'utm' => [], 'preview' => true,
        ]);
    }

    /** GET quotes/page-preview/{token}/result?a[…] — el resultado de la vista previa (no pide el contacto ni guarda) */
    public function pagePreviewResult(Request $request, string $token, PublicWidgetConfig $widgets): Response
    {
        Gate::authorize('ws.useLeads');
        $model = $this->previewModel($token);
        $workspace = $this->current->get();
        $page = '/api/w/'.$workspace->publicId().'/quotes/page-preview/'.$token;
        try {
            $estimate = QuoteEngine::estimate($model, QuotePage::answersFrom($request, (array) $model->definition));
        } catch (ValidationException $e) {
            return QuotePage::view('quotes.page', $workspace, QuotePageController::pageData($workspace, $model, $widgets,
                array_map(fn (array $m) => (string) ($m[0] ?? ''), $e->errors()), $request->query()) + [
                    'action' => $page.'/result', 'method' => 'get', 'utm' => [], 'preview' => true,
                ], 422);
        }
        $settings = QuoteCapture::for($model->capture);
        $currency = (string) (($model->definition['currency'] ?? null) ?: ($workspace->currency ?: 'COP'));

        return QuotePage::view('quotes.result', $workspace, [
            'name' => $model->name, 'range' => QuotePage::range($estimate['min'], $estimate['max'], $currency),
            'lines' => array_map(fn (array $l) => $l['label'], $estimate['lines']), 'note' => $model->definition['note'] ?? null,
            'message' => $settings['success'] ?? __('Es un valor estimado. Te contactaremos para confirmar el precio final.'),
            'whatsapp' => PublicQuoteController::whatsapp($settings['whatsapp_number'], $model->name, $estimate['min'], $estimate['max'], $currency, 'qt_…'),
            'again' => $page, 'reference' => null, 'preview' => true,
        ]);
    }

    /** GET quotes/calculators/{calculator}/qr?format=svg|png&download=1 — el QR de la página del cotizador */
    public function qr(Request $request, string $calculator): Response
    {
        Gate::authorize('ws.useLeads');
        $model = $this->find($calculator);

        return LandingPageController::qrResponse($request, QuotePage::qrUrl($this->current->get(), $model), 'qr-cotizador');
    }

    private function previewModel(string $token): QuoteCalculator
    {
        $draft = Cache::get(self::previewKey($this->current->id(), $token));
        abort_unless(is_array($draft), 404);
        $model = new QuoteCalculator(['name' => (string) $draft['name'], 'kind' => (string) $draft['kind'], 'status' => 'active']);
        $model->definition = (array) $draft['definition'];
        $model->capture = (array) $draft['capture'];

        return $model;
    }

    private static function previewKey(string $workspaceId, string $token): string
    {
        return 'ws:'.$workspaceId.':quote-preview:'.hash('sha256', $token);
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
            'page_url' => QuotePage::url(app(CurrentWorkspace::class)->get(), $c),
            'updated_at' => $c->updated_at?->toIso8601ZuluString(),
        ];
    }
}
