<?php

declare(strict_types=1);

namespace App\Domain\Consent\Http;

use App\Domain\Commerce\Support\EarlyCapture;
use App\Domain\Consent\Actions\ConsentTexts;
use App\Domain\Consent\Models\LegalText;
use App\Domain\Consent\Support\ConsentMode;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Textos de consentimiento versionados (cumplimiento.md §2 y §3.1). */
final class ConsentTextsController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly ConsentTexts $texts) {}

    /** GET /api/w/{workspace}/consent/texts */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $workspace = $this->current->get();
        $current = $this->texts->current($workspace);
        $history = LegalText::query()->where('kind', 'marketing_consent')->orderByDesc('published_at')->get()
            ->groupBy('channel')
            ->map(fn ($rows) => $rows->map(fn (LegalText $t) => ['version' => $t->version, 'label' => $t->body, 'published_at' => $t->published_at?->toIso8601String()])->values());
        $publishedAt = LegalText::query()->where('kind', 'marketing_consent')->get()->keyBy(fn (LegalText $t) => $t->channel.'|'.$t->version);

        return response()->json([
            'texts' => array_map(fn (array $t) => $t + ['published_at' => $publishedAt->get($t['channel'].'|'.$t['version'])?->published_at?->toIso8601String()], $current),
            'history' => $history,
            'mode' => ConsentMode::chosen($workspace),
            'privacy_policy_url' => $workspace->getAttribute('privacy_policy_url'),
            'early_capture' => $this->earlyCaptureState($workspace),
            'plugin_min_for_early_capture' => EarlyCapture::MIN_PLUGIN_VERSION,
        ]);
    }

    /** POST /api/w/{workspace}/consent/texts {channel, body} — publica una versión nueva. */
    public function store(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'channel' => ['required', Rule::in(ConsentTexts::CHANNELS)],
            'body' => ['required', 'string', 'min:20', 'max:600'],
        ]);
        $text = $this->texts->publish($this->current->get(), $data['channel'], $data['body']);
        $audit->handle('consent_text.published', $text, ['channel' => $data['channel'], 'version' => $text->version], $text->getAttribute('workspace_id'));

        return $this->index();
    }

    /**
     * PUT /api/w/{workspace}/consent/early-capture {enabled, accept_responsibility}
     *
     * Captura temprana del checkout (cumplimiento.md §3.2): apagada por defecto. Encenderla exige
     * aceptar la responsabilidad (el aviso junto al campo lo muestra el plugin) y queda en la auditoría.
     */
    public function earlyCapture(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'accept_responsibility' => ['exclude_unless:enabled,true', 'accepted'],
        ], [
            'accept_responsibility.accepted' => __('Para activar la captura temprana tienes que aceptar la responsabilidad.'),
        ]);
        $workspace = $this->current->get();
        $from = EarlyCapture::enabled($workspace);
        $to = (bool) $data['enabled'];
        $settings = $workspace->settings ?? [];
        $settings['early_capture'] = $to;
        if ($to && ! $from) {
            $settings['early_capture_accepted'] = ['at' => now()->toIso8601String(), 'user_id' => (string) auth()->id()];
        }
        $workspace->forceFill(['settings' => $settings])->save();
        if ($from !== $to) {
            $audit->handle('consent.early_capture_changed', $workspace, ['from' => $from, 'to' => $to, 'responsibility_accepted' => $to], $workspace->id);
        }

        return $this->index();
    }

    /** @return array{enabled: bool, accepted_at: string|null, accepted_by: string|null} */
    private function earlyCaptureState(Workspace $workspace): array
    {
        $accepted = (array) $workspace->setting('early_capture_accepted', []);
        $userId = isset($accepted['user_id']) && is_string($accepted['user_id']) ? $accepted['user_id'] : null;

        return [
            'enabled' => EarlyCapture::enabled($workspace),
            'accepted_at' => isset($accepted['at']) && is_string($accepted['at']) ? $accepted['at'] : null,
            'accepted_by' => $userId !== null ? User::query()->whereKey($userId)->value('name') : null,
        ];
    }

    /** PUT /api/w/{workspace}/consent/mode {mode} */
    public function mode(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate(['mode' => ['required', 'in:'.implode(',', ConsentMode::ALL)]]);
        $workspace = $this->current->get();
        $settings = $workspace->settings ?? [];
        $from = ConsentMode::chosen($workspace);
        $settings['consent_mode'] = $data['mode'];
        $workspace->forceFill(['settings' => $settings])->save();
        // Relajar el modo por defecto queda en la auditoría (cumplimiento.md, regla de diseño).
        $audit->handle('consent.mode_changed', $workspace, ['from' => $from, 'to' => $data['mode']], $workspace->id);

        return $this->index();
    }
}
