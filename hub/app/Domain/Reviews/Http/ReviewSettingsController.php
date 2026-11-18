<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Http;

use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Reviews\Actions\RewardReview;
use App\Domain\Reviews\Support\ReviewMediaStore;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de reseñas (M15). Ver: viewDashboards; cambiar: manageMarketing.
 * Reclasificar la solicitud como mensaje de utilidad (no comercial) exige ser
 * dueño o administrador y una justificación, y queda en la auditoría
 * (cumplimiento.md §4.1: lo ambiguo es marketing por defecto).
 */
final class ReviewSettingsController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET reviews/settings */
    public function show(LoyaltyLedger $ledger): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json([
            'settings' => ReviewSettings::for($this->current->get()),
            'options' => $this->options($ledger),
        ]);
    }

    /** PUT reviews/settings */
    public function update(Request $request, RecordAuditLog $audit, LoyaltyLedger $ledger): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'request' => ['required', 'array'],
            'request.enabled' => ['required', 'boolean'],
            'request.trigger' => ['required', 'in:completed,paid'],
            'request.days' => ['required', 'integer', 'between:0,120'],
            'request.category_days' => ['present', 'array', 'max:50'],
            'request.category_days.*.category_id' => ['required', 'string', 'max:64'],
            'request.category_days.*.days' => ['required', 'integer', 'between:0,120'],
            'request.channels' => ['required', 'array', 'min:1', 'max:3'],
            'request.channels.*' => ['in:whatsapp,email,sms', 'distinct'],
            'request.email_template_id' => ['nullable', 'string', 'max:40'],
            'request.whatsapp_template_id' => ['nullable', 'string', 'max:40'],
            'request.sms_template_id' => ['nullable', 'string', 'max:40'],
            'request.skip_reviewed' => ['required', 'boolean'],
            'request.kind' => ['required', 'in:marketing,utility'],
            'request.reclassify_reason' => ['nullable', 'string', 'max:500'],
            'request.link_days' => ['required', 'integer', 'between:7,180'],
            'moderation.mode' => ['required', 'in:manual,auto'],
            'google_review_url' => ['nullable', 'url:https', 'max:500'],
            'publish_to_store' => ['required', 'boolean'],
            'reply_author' => ['nullable', 'string', 'max:80'],
        ]);
        $workspace = $this->current->get();
        $before = ReviewSettings::for($workspace);
        $r = $data['request'];

        $errors = [];
        foreach (['email', 'whatsapp', 'sms'] as $channel) {
            $id = $r[$channel.'_template_id'] ?? null;
            if (is_string($id) && $id !== '' && TemplateRef::resolve($workspace->id, $channel, $id, null) === null) {
                $errors['request.'.$channel.'_template_id'] = __('Esa plantilla no existe o es de otro canal.');
            }
        }
        if (in_array('whatsapp', $r['channels'], true) && ($r['whatsapp_template_id'] ?? null) === null && $r['enabled']) {
            $errors['request.whatsapp_template_id'] = __('Elige la plantilla aprobada de WhatsApp o quita el canal.');
        }
        if (in_array('sms', $r['channels'], true) && ($r['sms_template_id'] ?? null) === null && $r['enabled']) {
            $errors['request.sms_template_id'] = __('Elige la plantilla de SMS o quita el canal.');
        }
        $kindChanged = $r['kind'] !== $before['request']['kind'];
        if ($r['kind'] === 'utility' && mb_strlen(trim((string) ($r['reclassify_reason'] ?? ''))) < 10) {
            $errors['request.reclassify_reason'] = __('Explica por qué la solicitud de reseña no es un mensaje de marketing (mínimo 10 caracteres).');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($kindChanged) {
            Gate::authorize('ws.manageWorkspace');
        }

        $stored = [
            'request' => [
                'enabled' => (bool) $r['enabled'],
                'trigger' => $r['trigger'],
                'days' => (int) $r['days'],
                'category_days' => array_values(array_map(fn (array $c) => ['category_id' => (string) $c['category_id'], 'days' => (int) $c['days']], $r['category_days'])),
                'channels' => array_values($r['channels']),
                'email_template_id' => $r['email_template_id'] ?? null,
                'whatsapp_template_id' => $r['whatsapp_template_id'] ?? null,
                'sms_template_id' => $r['sms_template_id'] ?? null,
                'skip_reviewed' => (bool) $r['skip_reviewed'],
                'kind' => $r['kind'],
                'reclassify_reason' => $r['kind'] === 'utility' ? trim((string) $r['reclassify_reason']) : null,
                'link_days' => (int) $r['link_days'],
            ],
            'moderation' => ['mode' => $data['moderation']['mode']],
            'google_review_url' => $data['google_review_url'] ?? null,
            'publish_to_store' => (bool) $data['publish_to_store'],
            'reply_author' => isset($data['reply_author']) && trim((string) $data['reply_author']) !== '' ? trim((string) $data['reply_author']) : null,
        ];
        ReviewSettings::save($workspace, $stored);

        if ($kindChanged) {
            $audit->handle('reviews.request_kind_changed', $workspace, [
                'from' => $before['request']['kind'], 'to' => $r['kind'], 'reason' => $stored['request']['reclassify_reason'],
            ], $workspace->id);
        }
        $audit->handle('reviews.settings_updated', $workspace, [
            'enabled' => $stored['request']['enabled'], 'moderation' => $stored['moderation']['mode'], 'channels' => $stored['request']['channels'],
        ], $workspace->id);

        return response()->json(['settings' => ReviewSettings::for($workspace), 'options' => $this->options($ledger)]);
    }

    /** @return array<string, mixed> */
    private function options(LoyaltyLedger $ledger): array
    {
        $ws = $this->current->id();
        $templates = Template::query()->where('status', '!=', 'archived')->orderBy('name')->get(['id', 'channel', 'name', 'category', 'library_key', 'status']);
        $byChannel = [];
        foreach (['email', 'whatsapp', 'sms'] as $channel) {
            $byChannel[$channel] = $templates->where('channel', $channel)->map(fn (Template $t) => [
                'id' => $t->publicId(), 'name' => $t->name, 'category' => $t->category, 'library_key' => $t->library_key, 'status' => $t->status,
            ])->values()->all();
        }
        $categories = DB::table('product_categories')->where('workspace_id', $ws)->whereNull('deleted_at')
            ->orderBy('name')->limit(500)->get(['external_id', 'name'])
            ->map(fn ($c) => ['id' => (string) $c->external_id, 'name' => (string) $c->name])->unique('id')->values()->all();

        return [
            'templates' => $byChannel,
            'categories' => $categories,
            'loyalty_active' => $ledger->isActive(),
            // Los puntos por reseña los define el programa de fidelización (Fidelización → Reglas).
            'rewards' => app(RewardReview::class)->preview(),
            'photos_supported' => ReviewMediaStore::supportsImages(),
            'can_reclassify' => Gate::allows('ws.manageWorkspace'),
        ];
    }
}
