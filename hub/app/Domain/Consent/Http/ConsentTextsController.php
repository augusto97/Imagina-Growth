<?php

declare(strict_types=1);

namespace App\Domain\Consent\Http;

use App\Domain\Consent\Actions\ConsentTexts;
use App\Domain\Consent\Models\LegalText;
use App\Domain\Consent\Support\ConsentMode;
use App\Domain\Workspaces\Actions\RecordAuditLog;
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
