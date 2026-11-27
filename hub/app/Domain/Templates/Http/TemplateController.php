<?php

declare(strict_types=1);

namespace App\Domain\Templates\Http;

use App\Domain\Billing\Support\PlatformSending;
use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Services\Verticals\VerticalCatalog;
use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Exceptions\TemplateRenderException;
use App\Domain\Templates\Library\InstallLibraryTemplate;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Templates\Library\WhatsAppLibrary;
use App\Domain\Templates\Liquid\LiquidSandbox;
use App\Domain\Templates\Models\Template;
use App\Domain\Templates\Rendering\LiquidTemplateRenderer;
use App\Domain\Templates\Rendering\SampleData;
use App\Domain\Templates\Rendering\TemplateVariables;
use App\Domain\Templates\Rendering\WhatsAppComponents;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Templates\Support\TemplateChecks;
use App\Domain\Templates\Support\VariableCatalog;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** API de plantillas de la SPA (modulos.md M08). Ver routes/api/templates.php. */
final class TemplateController
{
    private const MAX_HTML = 600_000;

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly LiquidSandbox $sandbox,
        private readonly LiquidTemplateRenderer $renderer,
    ) {}

    /** GET templates?channel=&status=&q= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate([
            'channel' => ['nullable', Rule::in(Template::CHANNELS)],
            'status' => ['nullable', Rule::in([...Template::STATUSES, 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $data['status'] ?? null;
        $templates = Template::query()
            ->when($data['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($status === null, fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($status !== null && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.addcslashes((string) $term, '%_').'%'))
            ->orderByDesc('updated_at')
            ->get(['id', 'workspace_id', 'channel', 'name', 'category', 'subject', 'body_text', 'status', 'library_key', 'wa_template_id', 'created_at', 'updated_at']);
        $wa = DB::table('wa_templates')->where('workspace_id', $this->current->id())
            ->whereIn('id', $templates->pluck('wa_template_id')->filter()->all())->get(['id', 'name', 'status', 'rejected_reason'])
            ->mapWithKeys(fn ($r) => [(string) $r->id => ['name' => (string) $r->name, 'status' => (string) $r->status, 'rejected_reason' => $r->rejected_reason !== null ? (string) $r->rejected_reason : null]]);
        // ¿Hay un número oficial de WhatsApp activo? Sin él, las de WhatsApp en borrador no se pueden enviar a Meta.
        $connected = DB::table('channel_accounts')->where('workspace_id', $this->current->id())->where('channel', 'whatsapp')
            ->where('provider', 'meta_cloud')->where('status', 'active')->exists();

        return response()->json([
            'data' => $templates->map(fn (Template $t) => TemplatePresenter::summary($t, $wa->get((string) $t->wa_template_id), false))->values(),
            'meta' => ['whatsapp_connected' => $connected],
        ]);
    }

    /** GET templates/{template} */
    public function show(string $template): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = $this->find($template);

        return response()->json(TemplatePresenter::full($model, TemplateChecks::run($model, $this->current->get())));
    }

    /** POST templates */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $this->validated($request, null);
        /** @var User $user */
        $user = $request->user();
        $model = Template::query()->create($data + ['workspace_id' => $this->current->id(), 'created_by' => $user->id]);

        return response()->json(TemplatePresenter::full($model, TemplateChecks::run($model, $this->current->get())), 201);
    }

    /** PATCH templates/{template} */
    public function update(Request $request, string $template): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($template);
        $model->fill($this->validated($request, $model));
        $checks = TemplateChecks::run($model, $this->current->get());
        if ($model->status === 'ready' && TemplateChecks::hasErrors($checks)) {
            throw ValidationException::withMessages(['status' => __('Corrige los errores antes de marcarla como lista: :list', [
                'list' => implode(' ', array_column(array_filter($checks, fn (array $c) => $c['level'] === 'error'), 'message')),
            ])]);
        }
        $model->save();

        return response()->json(TemplatePresenter::full($model, $checks));
    }

    /** POST templates/{template}/duplicate */
    public function duplicate(Request $request, string $template): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($template);
        /** @var User $user */
        $user = $request->user();
        $copy = $model->replicate(['library_key']);
        $copy->forceFill([
            'name' => mb_substr(__(':name (copia)', ['name' => $model->name]), 0, 160),
            'status' => 'draft',
            'created_by' => $user->id,
        ])->save();

        return response()->json(TemplatePresenter::full($copy, TemplateChecks::run($copy, $this->current->get())), 201);
    }

    /** DELETE templates/{template}: se archiva (los flujos y campañas que la usan conservan la referencia). */
    public function destroy(string $template, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($template);
        $model->forceFill(['status' => 'archived'])->save();
        $audit->handle('template.archived', $model, ['name' => $model->name], $this->current->id());

        return response()->json(['ok' => true]);
    }

    /** POST templates/{template}/preview {contact_id?} */
    public function preview(Request $request, string $template): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return $this->renderPreview($this->find($template), $request);
    }

    /** POST templates/preview: borrador sin guardar (el editor). */
    public function previewDraft(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate([
            'channel' => ['required', Rule::in(Template::CHANNELS)],
            'category' => ['nullable', Rule::in(Template::CATEGORIES)],
            'subject' => ['nullable', 'string', 'max:250'],
            'preheader' => ['nullable', 'string', 'max:250'],
            'body_html' => ['nullable', 'string', 'max:'.self::MAX_HTML],
            'body_text' => ['nullable', 'string', 'max:5000'],
            'wa_template_id' => ['nullable', 'string', 'max:40'],
            'variables' => ['nullable', 'array'],
        ]);
        $draft = new Template(['workspace_id' => $this->current->id(), 'category' => $data['category'] ?? 'marketing'] + array_intersect_key($data, array_flip(['channel', 'subject', 'preheader', 'body_html', 'body_text', 'variables'])));
        $draft->wa_template_id = $this->waTemplateId($data['wa_template_id'] ?? null);

        return $this->renderPreview($draft, $request);
    }

    /**
     * POST templates/test-send {subject, preheader, category, body_html, to}: el correo que hay en el editor (sin
     * guardar), con los datos de ejemplo de la vista previa, a una dirección por el canal de correo del espacio de
     * trabajo. Sale como prueba (`isTest`, transaccional: sin consentimiento ni ventana legal) y el asunto lleva
     * «[Prueba]». Responde en palabras si salió o qué falta.
     */
    public function testSend(Request $request, MessageDispatcher $dispatcher): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:250'],
            'preheader' => ['nullable', 'string', 'max:250'],
            'category' => ['nullable', Rule::in(Template::CATEGORIES)],
            'body_html' => ['required', 'string', 'max:'.self::MAX_HTML],
            'to' => ['required', 'string', 'max:190', 'email:rfc'],
        ]);
        $workspace = $this->current->get();
        $to = (string) $data['to'];
        $draft = new Template([
            'workspace_id' => $workspace->id,
            'channel' => 'email',
            'category' => $data['category'] ?? 'marketing',
            'subject' => $data['subject'] ?? '',
            'preheader' => $data['preheader'] ?? '',
            'body_html' => $data['body_html'],
        ]);
        $sample = SampleData::forWorkspace($workspace, (string) (TemplateVariables::store($workspace)['url'] ?? ''));
        try {
            $rendered = $this->renderer->renderTemplate($draft, new RenderContext($workspace, null, $sample));
        } catch (TemplateRenderException $e) {
            return response()->json(['sent' => false, 'to' => $to, 'message' => __('La plantilla tiene un error y no se puede enviar: :error', ['error' => $e->getMessage()])]);
        }
        $subject = trim((string) $rendered->subject);
        $result = $dispatcher->dispatch(new OutboundMessage(
            $workspace->id, null, Channel::Email, MessageKind::Transactional, 'system',
            context: [
                'subject' => __('[Prueba]').' '.($subject !== '' ? $subject : __('Correo de prueba de :name', ['name' => $workspace->name])),
                'html' => $rendered->html,
                'text' => $rendered->text,
            ],
            to: $to, isTest: true,
        ));
        $sent = $result->status === DispatchResult::QUEUED;

        return response()->json(['sent' => $sent, 'to' => $to, 'message' => $sent
            ? __('Llega en uno o dos minutos: revisa también la carpeta de spam. Los datos y los enlaces de baja son de ejemplo.')
            : self::testSendProblem((string) $result->reason)]);
    }

    /** Por qué no salió la prueba, en palabras. */
    private static function testSendProblem(string $reason): string
    {
        return match ($reason) {
            PolicyDecision::CHANNEL_UNAVAILABLE => __('No hay un correo conectado para enviar. Conéctalo en Configuración → Conexiones → Correo.'),
            PlatformSending::DENIED => __('Tu plan no incluye el envío de correos por la plataforma: conecta el correo de tu negocio en Configuración → Conexiones → Correo.'),
            'billing_suspended' => __('La cuenta está suspendida por un pago pendiente: los envíos están en pausa.'),
            default => __('No se pudo enviar la prueba (:reason). Intenta de nuevo en unos minutos.', ['reason' => $reason !== '' ? $reason : '—']),
        };
    }

    /** GET templates/variables: catálogo para el selector de variables del editor. */
    public function variables(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(['groups' => VariableCatalog::groups($this->current->get())]);
    }

    /**
     * GET templates/library?industry=&channel= — sin `channel`, las de correo y SMS (como antes de que la biblioteca
     * tuviera WhatsApp); `channel=whatsapp|email|sms` solo ese canal y `channel=all` todas. La galería
     * (`templates/gallery`) es la que muestra las de WhatsApp con su vista previa.
     */
    public function library(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $channel = $request->validate(['channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp', 'all'])]])['channel'] ?? null;
        $industry = $this->industry($request);
        // La más antigua de cada clave (la que usan los flujos): pluck se queda con la última fila.
        $installed = Template::query()->whereNotNull('library_key')->where('status', '!=', 'archived')
            ->orderByDesc('created_at')->orderByDesc('id')->pluck('id', 'library_key');

        return response()->json([
            'industry' => $industry,
            'industries' => TemplateLibrary::industries(),
            'data' => array_values(array_map(
                fn (array $e) => $e + ['installed_id' => isset($installed[$e['key']]) ? 'tpl_'.$installed[$e['key']] : null],
                array_filter(TemplateLibrary::catalog($industry), fn (array $e) => $channel === 'all' || ($channel === null ? $e['channel'] !== 'whatsapp' : $e['channel'] === $channel)),
            )),
        ]);
    }

    /**
     * GET templates/gallery?goal=&vertical=&channel=&industry= — la galería por objetivo y vertical (rediseño v2
     * §11.5). Sin `vertical`, la del espacio de trabajo; `vertical=all` muestra todas.
     */
    public function gallery(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate([
            'goal' => ['nullable', Rule::in(TemplateLibrary::GOALS)],
            'vertical' => ['nullable', Rule::in([...VerticalCatalog::keys(), 'all'])],
            'channel' => ['nullable', Rule::in(['email', 'whatsapp'])],
        ]);
        $own = VerticalCatalog::forWorkspace($this->current->get())['vertical'];
        $vertical = ($data['vertical'] ?? null) === 'all' ? null : ($data['vertical'] ?? $own);
        $industry = $this->industry($request) ?? VerticalCatalog::industry($vertical);
        $installed = Template::query()->whereNotNull('library_key')->where('status', '!=', 'archived')
            ->orderByDesc('created_at')->orderByDesc('id')->get(['id', 'library_key', 'status', 'wa_template_id'])->keyBy('library_key');

        return response()->json([
            'goals' => TemplateLibrary::GOALS,
            'verticals' => VerticalCatalog::keys(),
            'vertical' => $vertical,
            'industry' => $industry,
            'whatsapp_connected' => DB::table('channel_accounts')->where('workspace_id', $this->current->id())->where('channel', 'whatsapp')->where('provider', 'meta_cloud')->where('status', 'active')->exists(),
            'data' => array_map(function (array $e) use ($installed) {
                $mine = $installed->get($e['key']);

                return $e + ['installed' => $mine === null ? null : ['id' => 'tpl_'.$mine->id, 'status' => $mine->status, 'linked' => $mine->wa_template_id !== null]];
            }, TemplateLibrary::gallery($data['goal'] ?? null, $vertical, $data['channel'] ?? null, $industry)),
        ]);
    }

    /** GET templates/library/{key}/preview?industry= */
    public function libraryPreview(Request $request, string $key): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $entry = TemplateLibrary::entry($key) ?? abort(404);
        $draft = new Template(['workspace_id' => $this->current->id()] + TemplateLibrary::attributes($key, $this->industry($request)));
        if ($entry['channel'] === 'whatsapp') {
            // Todavía no está aprobada en Meta: la vista previa es el texto propuesto con los datos de ejemplo.
            $workspace = $this->current->get();
            $sample = SampleData::forWorkspace($workspace, (string) (TemplateVariables::store($workspace)['url'] ?? ''));
            try {
                $text = trim($this->sandbox->render((string) $draft->body_text, $sample + ['tienda' => TemplateVariables::store($workspace)], app(TemplateVariables::class)->register($workspace), __('el texto')));
            } catch (TemplateRenderException $e) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            $compiled = WhatsAppLibrary::compile($entry, (string) $draft->body_text);

            return response()->json(['ok' => true, 'text' => $text, 'whatsapp' => ['name' => $compiled['name'], 'category' => strtolower($compiled['category']),
                'body' => $compiled['body'], 'footer' => $compiled['footer'], 'buttons' => $compiled['buttons']]]);
        }

        return $this->renderPreview($draft, $request);
    }

    /**
     * POST templates/library/{key} {industry?, copy?, submit?, account?} — instala con un clic (idempotente). Las de
     * WhatsApp se envían a revisión de Meta si hay un número oficial activo (`submit`, sí por defecto).
     */
    public function install(Request $request, string $key, InstallLibraryTemplate $install): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        if (TemplateLibrary::entry($key) === null) {
            abort(404);
        }
        $data = $request->validate([
            'industry' => ['nullable', Rule::in(TemplateLibrary::industries())], 'copy' => ['nullable', 'boolean'],
            'submit' => ['nullable', 'boolean'], 'account' => ['nullable', 'string', 'max:40'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $result = $install->handle($this->current->get(), $key, $data['industry'] ?? null, $user->id, (bool) ($data['copy'] ?? false),
            ! array_key_exists('submit', $data) || (bool) $data['submit'], $data['account'] ?? null);
        $model = $result['template'];

        return response()->json(TemplatePresenter::full($model, TemplateChecks::run($model, $this->current->get())) + ['whatsapp_submission' => $result['whatsapp']], $result['created'] ? 201 : 200);
    }

    /** GET templates/wa-sources: plantillas de WhatsApp sincronizadas (M07) con las ranuras de parámetros. */
    public function waSources(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $rows = DB::table('wa_templates')->where('workspace_id', $this->current->id())->orderBy('name')
            ->get(['id', 'name', 'language', 'category', 'status', 'components']);

        return response()->json(['data' => $rows->map(function (object $row): array {
            $components = json_decode((string) $row->components, true);
            $components = is_array($components) ? array_values($components) : [];

            return [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'language' => (string) $row->language,
                'category' => strtolower((string) $row->category),
                'status' => strtolower((string) $row->status),
                'slots' => WhatsAppComponents::slots($components),
                'components' => $components,
            ];
        })->values()]);
    }

    /** GET templates/business-profile */
    public function businessProfile(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(BusinessProfile::for($this->current->get()));
    }

    /** PUT templates/business-profile */
    public function updateBusinessProfile(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:250'],
            'contact' => ['nullable', 'string', 'max:160'],
            'whatsapp' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'logo_url' => ['nullable', 'url:https', 'max:500'],
        ]);
        $workspace = $this->current->get();
        $settings = $workspace->settings ?? [];
        $settings['business_profile'] = array_map(fn ($v) => trim((string) $v), array_intersect_key($data, array_flip(BusinessProfile::FIELDS))) + BusinessProfile::for($workspace);
        $workspace->forceFill(['settings' => $settings])->save();
        $audit->handle('workspace.business_profile_updated', $workspace, [], $workspace->id);

        return response()->json(BusinessProfile::for($workspace));
    }

    private function renderPreview(Template $template, Request $request): JsonResponse
    {
        $data = $request->validate(['contact_id' => ['nullable', 'string', 'max:40']]);
        $workspace = $this->current->get();
        $contact = null;
        if (! empty($data['contact_id'])) {
            Gate::authorize('ws.viewAllContacts');
            $contact = ContactAccess::find((string) $data['contact_id']);
        }
        $sample = SampleData::forWorkspace($workspace, (string) (TemplateVariables::store($workspace)['url'] ?? ''));
        if ($contact instanceof Contact) {
            unset($sample['contacto']);
        }
        try {
            $rendered = $this->renderer->renderTemplate($template, new RenderContext($workspace, $contact, $sample));
        } catch (TemplateRenderException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'checks' => TemplateChecks::run($template, $workspace)], 422);
        }

        return response()->json([
            'ok' => true,
            'subject' => $rendered->subject,
            'preheader' => $rendered->preheader,
            'html' => $rendered->html,
            'text' => $rendered->text,
            'whatsapp' => $rendered->waTemplateName === null ? null : [
                'name' => $rendered->waTemplateName,
                'language' => $rendered->waLanguage,
                'components' => $rendered->waComponents,
            ],
            'sms' => $template->channel === 'sms' ? TemplateChecks::smsSegments((string) $rendered->text) : null,
            'checks' => TemplateChecks::run($template, $workspace),
            'contact' => $contact === null ? null : ['id' => $contact->publicId(), 'name' => $contact->displayName()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Template $model): array
    {
        $creating = $model === null;
        $data = $request->validate([
            'channel' => [$creating ? 'required' : 'prohibited', Rule::in(Template::CHANNELS)],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'category' => ['sometimes', Rule::in(Template::CATEGORIES)],
            'status' => ['sometimes', Rule::in(['draft', 'ready'])],
            'subject' => ['sometimes', 'nullable', 'string', 'max:250'],
            'preheader' => ['sometimes', 'nullable', 'string', 'max:250'],
            'body_mjml' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_HTML],
            'body_html' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_HTML],
            'body_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'editor_state' => ['sometimes', 'nullable', 'string', 'max:2000000'],
            'wa_template_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'variables' => ['sometimes', 'nullable', 'array'],
            'variables.header' => ['sometimes', 'array'],
            'variables.header.*' => ['nullable', 'string', 'max:500'],
            'variables.body' => ['sometimes', 'array'],
            'variables.body.*' => ['nullable', 'string', 'max:500'],
            'variables.buttons' => ['sometimes', 'array'],
            'variables.buttons.*' => ['nullable', 'string', 'max:500'],
            'variables.header_media' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if (array_key_exists('wa_template_id', $data)) {
            $data['wa_template_id'] = $this->waTemplateId($data['wa_template_id']);
        }
        // Sintaxis de Liquid: se avisa al guardar, con el campo exacto.
        $errors = [];
        foreach (['subject', 'preheader', 'body_html', 'body_text'] as $field) {
            if (is_string($data[$field] ?? null) && ($error = $this->sandbox->syntaxError($data[$field])) !== null) {
                $errors[$field] = __('Error de Liquid: :msg', ['msg' => $error]);
            }
        }
        foreach ((array) ($data['variables'] ?? []) as $part => $values) {
            foreach (is_array($values) ? $values : [$values] as $key => $source) {
                if (is_string($source) && ($error = $this->sandbox->syntaxError($source)) !== null) {
                    $errors["variables.{$part}".(is_array($values) ? ".{$key}" : '')] = __('Error de Liquid: :msg', ['msg' => $error]);
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function waTemplateId(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = str_contains($value, '_') ? substr($value, (int) strpos($value, '_') + 1) : $value;
        $exists = DB::table('wa_templates')->where('workspace_id', $this->current->id())->where('id', $id)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['wa_template_id' => __('La plantilla de WhatsApp no existe en este espacio de trabajo.')]);
        }

        return $id;
    }

    private function industry(Request $request): ?string
    {
        $industry = $request->query('industry');
        if (is_string($industry) && in_array($industry, TemplateLibrary::industries(), true)) {
            return $industry;
        }
        $own = $this->current->get()->getAttribute('industry');

        return is_string($own) && in_array($own, TemplateLibrary::industries(), true) ? $own : null;
    }

    private function find(string $publicId): Template
    {
        return Template::findByPublicId($publicId) ?? abort(404);
    }
}
