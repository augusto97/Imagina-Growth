<?php

declare(strict_types=1);

namespace App\Domain\Templates\Http;

use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Exceptions\TemplateRenderException;
use App\Domain\Templates\Library\TemplateLibrary;
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
            ->get(['id', 'workspace_id', 'channel', 'name', 'category', 'subject', 'status', 'library_key', 'wa_template_id', 'created_at', 'updated_at']);
        $waNames = DB::table('wa_templates')->where('workspace_id', $this->current->id())
            ->whereIn('id', $templates->pluck('wa_template_id')->filter()->all())->pluck('name', 'id');

        return response()->json(['data' => $templates->map(fn (Template $t) => TemplatePresenter::summary($t, $waNames[$t->wa_template_id] ?? null))->values()]);
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

    /** GET templates/variables: catálogo para el selector de variables del editor. */
    public function variables(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(['groups' => VariableCatalog::groups()]);
    }

    /** GET templates/library?industry= */
    public function library(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $industry = $this->industry($request);
        // La más antigua de cada clave (la que usan los flujos): pluck se queda con la última fila.
        $installed = Template::query()->whereNotNull('library_key')->where('status', '!=', 'archived')
            ->orderByDesc('created_at')->orderByDesc('id')->pluck('id', 'library_key');

        return response()->json([
            'industry' => $industry,
            'industries' => TemplateLibrary::industries(),
            'data' => array_map(fn (array $e) => $e + ['installed_id' => isset($installed[$e['key']]) ? 'tpl_'.$installed[$e['key']] : null], TemplateLibrary::catalog($industry)),
        ]);
    }

    /** GET templates/library/{key}/preview?industry= */
    public function libraryPreview(Request $request, string $key): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        if (TemplateLibrary::entry($key) === null) {
            abort(404);
        }
        $draft = new Template(['workspace_id' => $this->current->id()] + TemplateLibrary::attributes($key, $this->industry($request)));

        return $this->renderPreview($draft, $request);
    }

    /** POST templates/library/{key} {industry?, copy?} */
    public function install(Request $request, string $key): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        if (TemplateLibrary::entry($key) === null) {
            abort(404);
        }
        $data = $request->validate(['industry' => ['nullable', Rule::in(TemplateLibrary::industries())], 'copy' => ['nullable', 'boolean']]);
        /** @var User $user */
        $user = $request->user();
        $model = TemplateLibrary::install($this->current->get(), $key, $data['industry'] ?? null, $user->id, (bool) ($data['copy'] ?? false));

        return response()->json(TemplatePresenter::full($model, TemplateChecks::run($model, $this->current->get())), $model->wasRecentlyCreated ? 201 : 200);
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
        $sample = SampleData::vars((string) (TemplateVariables::store($workspace)['url'] ?? ''));
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
