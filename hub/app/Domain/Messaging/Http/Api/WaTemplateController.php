<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Api;

use App\Domain\Messaging\Channels\Drivers\MetaCloudDriver;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Messaging\Support\WaTemplateBuilder;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Plantillas de WhatsApp de cada número (M07/M08): se crean y editan desde la
 * app y se envían a revisión de Meta. Una plantilla de utilidad con palabras de
 * marketing pide confirmación explícita (cumplimiento.md §5.1: disfrazar marketing
 * de utilidad está prohibido).
 */
final class WaTemplateController
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /** GET /api/w/{ws}/wa-templates?account=cha_… */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $query = WaTemplate::query()->orderBy('name');
        if ($request->filled('account')) {
            $account = ChannelAccount::findByPublicId((string) $request->query('account')) ?? abort(404);
            $query->where('channel_account_id', $account->id);
        }

        return response()->json(['data' => $query->get()->map(fn (WaTemplate $t) => self::present($t))->values()]);
    }

    /** POST /api/w/{ws}/wa-templates */
    public function store(Request $request, MetaCloudDriver $meta): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $this->validated($request, true);
        $account = ChannelAccount::findByPublicId($data['account']) ?? abort(404);
        abort_unless($account->provider === 'meta_cloud' && $account->isActive(), 422, __('Las plantillas son de un número de WhatsApp oficial activo.'));
        if (WaTemplate::query()->where('channel_account_id', $account->id)->where('name', $data['name'])->where('language', $data['language'])->exists()) {
            throw ValidationException::withMessages(['name' => __('Ya hay una plantilla con ese nombre e idioma en este número.')]);
        }
        $components = WaTemplateBuilder::components($data);
        $this->guardCategory($data, $components);
        $template = new WaTemplate([
            'channel_account_id' => $account->id, 'name' => $data['name'], 'language' => $data['language'],
            'category' => $data['category'], 'status' => 'PENDING', 'components' => $components,
        ]);
        try {
            [$metaId, $status] = $meta->submitTemplate($account, $template);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['name' => __('Meta no aceptó la plantilla: :msg', ['msg' => $e->getMessage()])]);
        }
        $template->fill(['meta_template_id' => $metaId, 'status' => $status, 'synced_at' => now()])->save();
        $this->audit->handle('wa_template.submitted', $template, ['name' => $template->name, 'category' => $template->category], $template->workspace_id);

        return response()->json(['data' => self::present($template)], 201);
    }

    /** PUT /api/w/{ws}/wa-templates/{id} — edita y vuelve a revisión. */
    public function update(Request $request, string $template, MetaCloudDriver $meta): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = WaTemplate::findByPublicId($template) ?? abort(404);
        $account = ChannelAccount::query()->findOrFail($model->channel_account_id);
        $data = $this->validated($request, false) + ['category' => $model->category];
        $components = WaTemplateBuilder::components($data);
        $this->guardCategory($data, $components);
        try {
            $meta->editTemplate($account, $model, $components);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['body.text' => __('Meta no aceptó el cambio: :msg', ['msg' => $e->getMessage()])]);
        }
        $model->forceFill(['components' => $components, 'status' => 'PENDING', 'rejected_reason' => null, 'synced_at' => now()])->save();
        $this->audit->handle('wa_template.edited', $model, ['name' => $model->name], $model->workspace_id);

        return response()->json(['data' => self::present($model)]);
    }

    /** DELETE /api/w/{ws}/wa-templates/{id} */
    public function destroy(string $template, MetaCloudDriver $meta): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = WaTemplate::findByPublicId($template) ?? abort(404);
        $account = ChannelAccount::query()->find($model->channel_account_id);
        if ($account !== null && $account->isActive()) {
            $meta->deleteTemplate($account, $model->name);
        }
        $this->audit->handle('wa_template.deleted', $model, ['name' => $model->name], $model->workspace_id);
        $model->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate(array_filter([
            'account' => $creating ? ['required', 'string'] : null,
            'name' => $creating ? ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'] : null,
            'language' => $creating ? ['required', Rule::in(['es', 'es_CO', 'es_MX', 'es_AR', 'es_ES', 'en', 'en_US', 'pt_BR'])] : null,
            'category' => $creating ? ['required', Rule::in(WaTemplate::CATEGORIES)] : null,
            'header' => ['nullable', 'array'],
            'header.format' => ['nullable', Rule::in(['NONE', 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'])],
            'header.text' => ['nullable', 'string', 'max:60'],
            'header.example' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'array'],
            'body.text' => ['required', 'string', 'max:1024'],
            'body.examples' => ['nullable', 'array', 'max:20'],
            'body.examples.*' => ['nullable', 'string', 'max:200'],
            'footer' => ['nullable', 'array'],
            'footer.text' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:10'],
            'buttons.*.type' => ['required', Rule::in(['URL', 'QUICK_REPLY', 'PHONE_NUMBER', 'COPY_CODE'])],
            'buttons.*.text' => ['nullable', 'string', 'max:25'],
            'buttons.*.url' => ['nullable', 'url', 'max:2000'],
            'buttons.*.tracked' => ['nullable', 'boolean'],
            'buttons.*.phone' => ['nullable', 'string', 'max:20'],
            'buttons.*.example' => ['nullable', 'string', 'max:20'],
            'acknowledge_category_warning' => ['nullable', 'boolean'],
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $components
     */
    private function guardCategory(array $data, array $components): void
    {
        $words = WaTemplateBuilder::marketingWords((string) $data['category'], $components);
        if ($words !== [] && empty($data['acknowledge_category_warning'])) {
            throw ValidationException::withMessages(['category' => __('Esta plantilla parece de marketing (:words). Disfrazar marketing de utilidad está prohibido por Meta y puede bloquear el número. Confirma que es una plantilla de utilidad o cámbiala a marketing.', ['words' => implode(', ', $words)])]);
        }
    }

    /** @return array<string, mixed> */
    public static function present(WaTemplate $t): array
    {
        return [
            'id' => $t->publicId(),
            'account_id' => ChannelAccount::ID_PREFIX.'_'.$t->channel_account_id,
            'name' => $t->name,
            'language' => $t->language,
            'category' => $t->category,
            'status' => $t->status,
            'quality' => $t->quality,
            'components' => $t->components,
            'rejected_reason' => $t->rejected_reason,
            'synced_at' => $t->synced_at?->toIso8601String(),
        ];
    }
}
