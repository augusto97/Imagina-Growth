<?php

declare(strict_types=1);

namespace App\Domain\Capture\Http;

use App\Domain\Capture\LeadAds\AdLeadData;
use App\Domain\Capture\LeadAds\MetaLeadAds;
use App\Domain\Capture\LeadAds\ProcessAdLead;
use App\Domain\Capture\Models\LeadAdLead;
use App\Domain\Capture\Models\LeadAdSource;
use App\Domain\Connections\Assets\MetaAssets;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\MetaProvider;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Connections\Support\ConnectionTokens;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Ids\PrefixedId;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Leads de anuncios en el panel (modulos.md M18 «Leads de anuncios»): el estado de Meta Lead Ads (la conexión, los
 * permisos que faltan dichos en palabras y las páginas suscritas) y del webhook de los formularios de Google Ads (la URL
 * y la clave, que se ve una sola vez y se puede rotar), la asignación de las casillas de consentimiento por formulario
 * y los últimos leads recibidos. Ver: `viewDashboards`; cambiar: `manageServices` (con auditoría). Conectar exige el
 * módulo `services` del plan. Nunca devuelve tokens ni la clave guardada.
 */
final class LeadAdsController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET capture/lead-ads */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(['meta' => $this->metaState(), 'google' => $this->googleState()]);
    }

    /** GET capture/lead-ads/meta/pages: las páginas que autorizó la conexión de Meta, marcando las suscritas. */
    public function metaPages(ConnectionTokens $tokens, MetaProvider $meta): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $connection = $this->metaConnection();
        try {
            $response = $meta->get('me/accounts', ['fields' => 'id,name', 'limit' => 100], $tokens->accessToken($connection));
        } catch (ConnectionError $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }
        if (! $response->successful()) {
            return response()->json(['message' => MetaLeadAds::explain($response), 'reason' => 'provider'], 422);
        }
        $subscribed = LeadAdSource::query()->where('platform', LeadAdSource::META)->where('status', '!=', 'disabled')->pluck('id', 'external_id');

        return response()->json(['data' => collect((array) $response->json('data', []))->filter(fn ($p) => is_array($p) && is_scalar($p['id'] ?? null))
            ->map(fn (array $p) => ['page_id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? $p['id']),
                'source_id' => isset($subscribed[(string) $p['id']]) ? PrefixedId::encode(LeadAdSource::ID_PREFIX, (string) $subscribed[(string) $p['id']]) : null])
            ->values()]);
    }

    /** POST capture/lead-ads/meta/pages {page_id}: suscribe la página a `leadgen` con el token de la página. */
    public function subscribeMeta(Request $request, MetaAssets $assets, MetaLeadAds $leadAds): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $data = $request->validate(['page_id' => ['required', 'string', 'regex:/^\d{1,40}$/']]);
        $connection = $this->metaConnection();
        $missing = MetaLeadAds::missingScopes($connection);
        if ($missing !== []) {
            throw ValidationException::withMessages(['page_id' => self::missingMessage($missing)]);
        }
        $taken = LeadAdSource::withoutWorkspaceScope()->where('platform', LeadAdSource::META)->where('external_id', $data['page_id'])
            ->where('status', '!=', 'disabled')->where('workspace_id', '!=', $this->current->id())->exists();
        if ($taken) {
            throw ValidationException::withMessages(['page_id' => __('Esa página ya recibe sus clientes potenciales en otro espacio de trabajo.')]);
        }
        try {
            $page = $assets->pageToken($connection, (string) $data['page_id']);
        } catch (ConnectionError $e) {
            throw ValidationException::withMessages(['page_id' => $e->getMessage()]);
        }
        $error = $leadAds->subscribe($page['id'], $page['access_token']);
        $source = LeadAdSource::query()->where('platform', LeadAdSource::META)->where('external_id', $page['id'])->first() ?? new LeadAdSource([
            'workspace_id' => $this->current->id(), 'platform' => LeadAdSource::META, 'external_id' => $page['id'],
        ]);
        $source->forceFill([
            'name' => mb_substr($page['name'], 0, 160),
            'secret' => $page['access_token'],
            'oauth_connection_id' => $connection->id,
            'status' => $error === null ? 'active' : 'error',
            'last_error' => $error !== null ? mb_substr(__('No se pudo suscribir la página a los clientes potenciales: :msg', ['msg' => $error]), 0, 500) : null,
        ])->save();
        $this->audit->handle('lead_ads.meta_page_connected', $source, ['page' => $page['id'], 'ok' => $error === null], $this->current->id());

        return response()->json(self::source($source), $error === null ? 201 : 422);
    }

    /** DELETE capture/lead-ads/meta/pages/{source}: deja de recibir los clientes potenciales de la página. */
    public function unsubscribeMeta(string $source, MetaLeadAds $leadAds): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $model = $this->find($source, LeadAdSource::META);
        try {
            if ($model->secret !== null && $model->external_id !== null) {
                $leadAds->unsubscribe($model->external_id, $model->secret);
            }
        } catch (ConnectionError) {
            // Si Meta no responde, se apaga igual en la plataforma: los avisos que lleguen se ignoran.
        }
        $model->forceFill(['status' => 'disabled', 'secret' => null])->save();
        $this->audit->handle('lead_ads.meta_page_disconnected', $model, ['page' => $model->external_id], $this->current->id());

        return response()->json(self::source($model));
    }

    /**
     * GET capture/lead-ads/sources/{source}/forms: los formularios con sus preguntas y casillas (Meta, por la Graph API) o
     * los que ya mandaron leads (Google), con lo asignado a cada uno.
     */
    public function forms(string $source, MetaLeadAds $leadAds): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $model = $this->find($source);
        if ($model->platform === LeadAdSource::META) {
            try {
                $forms = $leadAds->forms($model);
            } catch (ConnectionError $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        } else {
            $forms = $this->googleForms($model);
        }

        return response()->json(['data' => array_map(fn (array $f) => $f + ['mapping' => $model->form($f['id'])], $forms)]);
    }

    /** PUT capture/lead-ads/sources/{source}/forms/{form} {consent: [{key, channel}], service_key?} */
    public function saveForm(Request $request, string $source, string $form, MetaLeadAds $leadAds): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,64}$/', $form) === 1, 404);
        $model = $this->find($source);
        $data = $request->validate([
            'consent' => ['present', 'array', 'max:3'],
            'consent.*.key' => ['required', 'string', 'max:80'],
            'consent.*.channel' => ['required', Rule::in(['email', 'whatsapp', 'sms'])],
            'service_key' => ['nullable', 'string', 'max:80'],
        ]);
        $known = null;
        $name = null;
        if ($model->platform === LeadAdSource::META) {
            try {
                $found = collect($leadAds->forms($model))->firstWhere('id', $form);
            } catch (ConnectionError $e) {
                throw ValidationException::withMessages(['consent' => $e->getMessage()]);
            }
            abort_if($found === null, 404);
            $name = $found['name'];
            $known = ['questions' => collect($found['questions'])->keyBy('key'), 'checkboxes' => collect($found['checkboxes'])->keyBy('key')];
        }
        $consent = [];
        foreach ((array) $data['consent'] as $i => $rule) {
            $key = AdLeadData::key((string) $rule['key']);
            $text = null;
            if ($known !== null) {
                $box = $known['checkboxes']->get($key);
                if ($box === null && ! $known['questions']->has($key)) {
                    throw ValidationException::withMessages(["consent.{$i}.key" => __('Esa pregunta o casilla no está en el formulario.')]);
                }
                // Nada pre-marcado (cumplimiento.md §2): una casilla marcada por defecto no prueba que la persona aceptara.
                if ($box !== null && $box['checked_by_default']) {
                    throw ValidationException::withMessages(["consent.{$i}.key" => __('Esa casilla viene marcada por defecto en el formulario de Meta: no sirve como consentimiento. Desmárcala en el formulario y vuelve a intentarlo.')]);
                }
                $text = $box['text'] ?? $known['questions']->get($key)['label'] ?? null;
            }
            $consent[] = ['key' => $key, 'channel' => (string) $rule['channel'], 'text' => $text !== null ? mb_substr((string) $text, 0, 600) : null];
        }
        $settings = (array) ($model->settings ?? []);
        $settings['forms'] = (array) ($settings['forms'] ?? []);
        $settings['forms'][$form] = array_filter([
            'name' => $name ?? ($settings['forms'][$form]['name'] ?? null),
            'consent' => $consent,
            'service_key' => isset($data['service_key']) && is_string($data['service_key']) && $data['service_key'] !== '' ? AdLeadData::key($data['service_key']) : null,
        ], fn ($v) => $v !== null);
        $model->forceFill(['settings' => $settings])->save();
        $this->audit->handle('lead_ads.form_mapped', $model, ['form' => $form, 'channels' => array_column($consent, 'channel')], $this->current->id());

        return response()->json(['mapping' => $model->form($form)]);
    }

    /**
     * POST capture/lead-ads/google: crea el webhook o rota su clave. La clave solo se ve en esta respuesta: se guarda
     * cifrada y Google la manda en cada aviso (`google_key`).
     */
    public function google(): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $source = LeadAdSource::query()->where('platform', LeadAdSource::GOOGLE)->first() ?? new LeadAdSource([
            'workspace_id' => $this->current->id(), 'platform' => LeadAdSource::GOOGLE, 'name' => 'Google Ads',
            'token' => 'glf_'.Str::random(32),
        ]);
        $rotated = $source->exists;
        $key = Str::random(40);
        $source->forceFill(['secret' => $key, 'status' => 'active', 'last_error' => null])->save();
        $this->audit->handle($rotated ? 'lead_ads.google_key_rotated' : 'lead_ads.google_enabled', $source, [], $this->current->id());

        return response()->json($this->googleState() + ['key' => $key], $rotated ? 200 : 201);
    }

    /** DELETE capture/lead-ads/google: apaga el webhook (los avisos responden 404). */
    public function disableGoogle(): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $source = LeadAdSource::query()->where('platform', LeadAdSource::GOOGLE)->first();
        abort_if($source === null, 404);
        $source->forceFill(['status' => 'disabled', 'secret' => null])->save();
        $this->audit->handle('lead_ads.google_disabled', $source, [], $this->current->id());

        return response()->json($this->googleState());
    }

    /** GET capture/lead-ads/leads?platform=meta|google&status= : los últimos 50 (los de prueba, marcados). */
    public function leads(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $full = Gate::allows('ws.viewAllContacts');
        $platform = $request->query('platform');
        $status = $request->query('status');
        $rows = LeadAdLead::query()
            ->when(in_array($platform, [LeadAdSource::META, LeadAdSource::GOOGLE], true), fn ($q) => $q->where('platform', $platform))
            ->when(in_array($status, ['received', 'processed', 'failed', 'test'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('received_at')->limit(50)->get();
        $contacts = Contact::query()->whereIn('id', $rows->pluck('contact_id')->filter()->all())->get()->keyBy('id');

        return response()->json(['data' => $rows->map(function (LeadAdLead $r) use ($contacts, $full): array {
            $contact = $r->contact_id !== null ? $contacts->get($r->contact_id) : null;
            $data = (array) ($r->data ?? []);

            return [
                'id' => $r->publicId(),
                'platform' => $r->platform,
                'status' => $r->status,
                'is_test' => $r->is_test,
                'error' => $r->error,
                'form_id' => $r->form_id,
                'form_name' => $data['form_name'] ?? null,
                'campaign' => ['id' => $r->campaign_id, 'name' => $data['campaign_name'] ?? null],
                'adset' => ['id' => $r->adset_id, 'name' => $data['adset_name'] ?? null],
                'ad' => ['id' => $r->ad_id, 'name' => $data['ad_name'] ?? null],
                'consents' => $r->consents ?? [],
                'lead_id' => $r->lead_id !== null ? 'ld_'.$r->lead_id : null,
                'contact' => $contact !== null ? [
                    'id' => $contact->publicId(),
                    'name' => trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) ?: null,
                    'email' => $full ? $contact->email : ($contact->email !== null ? Pii::maskEmail($contact->email) : null),
                    'phone' => $full ? $contact->phone : ($contact->phone !== null ? Pii::maskPhone($contact->phone) : null),
                ] : null,
                'received_at' => $r->received_at->toIso8601String(),
                'processed_at' => $r->processed_at?->toIso8601String(),
            ];
        })->values()]);
    }

    /** POST capture/lead-ads/leads/{lead}/retry: vuelve a procesar un lead que falló (p. ej. después de reconectar). */
    public function retry(string $lead): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $row = LeadAdLead::findByPublicId($lead);
        abort_if($row === null, 404);
        abort_unless($row->status === 'failed', 409, __('Solo se reintenta un lead que falló.'));
        $source = LeadAdSource::query()->find($row->source_id);
        if ($source !== null && $source->status === 'error') {
            $source->forceFill(['status' => 'active', 'last_error' => null])->save();
        }
        $row->forceFill(['status' => 'received', 'error' => null])->save();
        ProcessAdLead::dispatch($row->workspace_id, $row->id);

        return response()->json(['status' => $row->fresh()?->status]);
    }

    /** @return array<string, mixed> */
    private function metaState(): array
    {
        $connection = MetaLeadAds::connection();
        $sources = LeadAdSource::query()->where('platform', LeadAdSource::META)->where('status', '!=', 'disabled')->orderBy('created_at')->get();
        $missing = $connection !== null ? MetaLeadAds::missingScopes($connection) : [];
        [$status, $message] = match (true) {
            (new MetaProvider)->missing() !== [] => ['not_configured', __('Esta instalación todavía no tiene la app de Meta configurada. Quien administra la plataforma debe completarla en Plataforma → Ajustes → Meta.')],
            $connection === null => ['not_connected', __('Conecta Meta en Conexiones para recibir aquí los clientes potenciales de tus anuncios de Facebook e Instagram.')],
            ! $connection->isActive() => ['reconnect', __('La conexión con Meta se cerró o venció. Vuelve a conectarla en Conexiones.')],
            $missing !== [] => ['missing_permissions', self::missingMessage($missing)],
            $sources->contains(fn (LeadAdSource $s) => $s->status === 'error') => ['error', (string) $sources->firstWhere('status', 'error')?->last_error],
            $sources->isEmpty() => ['ready', __('Elige la página de Facebook de tus anuncios para empezar a recibir sus clientes potenciales.')],
            default => ['active', __('Recibiendo los clientes potenciales de tus anuncios.')],
        };

        return [
            'status' => $status,
            'message' => $message,
            'connection' => $connection !== null ? ['id' => $connection->publicId(), 'status' => $connection->status, 'account_name' => $connection->account_name] : null,
            'missing_permissions' => $missing,
            'pages' => $sources->map(fn (LeadAdSource $s) => self::source($s))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function googleState(): array
    {
        $source = LeadAdSource::query()->where('platform', LeadAdSource::GOOGLE)->first();
        $active = $source !== null && $source->status === 'active' && $source->secret !== null;

        return [
            'status' => $active ? 'active' : 'not_configured',
            'message' => $active
                ? __('Pega la URL y la clave en Google Ads → el formulario de clientes potenciales → Integración de webhook, y usa «Enviar datos de prueba».')
                : __('Genera la URL y la clave del webhook para recibir aquí los clientes potenciales de tus formularios de Google Ads.'),
            'webhook_url' => $active ? rtrim((string) config('app.url'), '/').'/webhooks/google/lead-forms/'.$source->token : null,
            'key_hint' => $active ? '••••'.substr((string) $source->secret, -4) : null,
            'source' => $source !== null ? self::source($source) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function source(LeadAdSource $s): array
    {
        $since = now()->subDays(30);

        return [
            'id' => $s->publicId(),
            'platform' => $s->platform,
            'page_id' => $s->platform === LeadAdSource::META ? $s->external_id : null,
            'name' => $s->name,
            'status' => $s->status,
            'last_error' => $s->last_error,
            'last_lead_at' => $s->last_lead_at?->toIso8601String(),
            'test_received_at' => $s->test_received_at?->toIso8601String(),
            'forms_mapped' => count((array) (($s->settings ?? [])['forms'] ?? [])),
            'leads_30d' => LeadAdLead::query()->where('source_id', $s->id)->where('is_test', false)->where('received_at', '>=', $since)->count(),
            'with_consent_30d' => LeadAdLead::query()->where('source_id', $s->id)->where('is_test', false)->where('received_at', '>=', $since)
                ->whereNotNull('consents')->where('consents', '!=', '[]')->count(),
        ];
    }

    /**
     * Los formularios de Google que ya mandaron leads, con sus preguntas (de lo recibido).
     *
     * @return list<array{id: string, name: string, status: null, questions: list<array{key: string, label: string, type: null}>, checkboxes: list<never>}>
     */
    private function googleForms(LeadAdSource $source): array
    {
        $out = [];
        foreach (LeadAdLead::query()->where('source_id', $source->id)->whereNotNull('form_id')->orderByDesc('received_at')->limit(200)->get() as $row) {
            $form = (string) $row->form_id;
            if (isset($out[$form])) {
                continue;
            }
            $data = (array) ($row->data ?? []);
            $labels = (array) ($data['labels'] ?? []);
            if ($labels === [] && is_array($data['payload'] ?? null)) {
                $labels = AdLeadData::fromGoogle($data['payload'])->labels;
            }
            $out[$form] = ['id' => $form, 'name' => (string) ($source->form($form)['name'] ?? $form), 'status' => null,
                'questions' => array_values(array_map(fn ($k, $l) => ['key' => (string) $k, 'label' => (string) $l, 'type' => null], array_keys($labels), $labels)),
                'checkboxes' => []];
        }

        return array_values($out);
    }

    /** @param list<array{scope: string, label: string}> $missing */
    private static function missingMessage(array $missing): string
    {
        return __('A la conexión con Meta le faltan permisos para: :list. Pide a quien conectó Meta que vuelva a conectarla en Conexiones y acepte todos los permisos.', [
            'list' => implode(', ', array_column($missing, 'label')),
        ]);
    }

    private function metaConnection(): OAuthConnection
    {
        $connection = MetaLeadAds::connection();
        if ($connection === null || ! $connection->isActive()) {
            throw ValidationException::withMessages(['connection' => __('Conecta Meta en Conexiones para recibir los clientes potenciales de tus anuncios.')]);
        }

        return $connection;
    }

    private function find(string $publicId, ?string $platform = null): LeadAdSource
    {
        $source = LeadAdSource::findByPublicId($publicId);
        abort_if($source === null || ($platform !== null && $source->platform !== $platform), 404);

        return $source;
    }
}
