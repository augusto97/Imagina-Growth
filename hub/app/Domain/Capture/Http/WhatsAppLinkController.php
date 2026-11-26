<?php

declare(strict_types=1);

namespace App\Domain\Capture\Http;

use App\Domain\Capture\Models\WhatsAppLink;
use App\Domain\Capture\Models\WhatsAppLinkHit;
use App\Domain\Capture\Support\CaptureCounters;
use App\Domain\Capture\Support\CaptureUrls;
use App\Domain\Capture\Support\WaCodes;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * API de los chats de WhatsApp con atribución (el QR para imprimir del local, rediseño v2 §11.3). Ver:
 * `viewDashboards`; crear, editar y archivar: `manageMarketing` (con auditoría). Los botones de las páginas de
 * captura (`kind = page`) se listan con `?kind=page`, pero se editan desde su página.
 */
final class WhatsAppLinkController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET capture/whatsapp-links?kind=qr|page&days=30 */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $kind = $request->query('kind') === 'page' ? 'page' : 'qr';
        $links = WhatsAppLink::query()->where('kind', $kind)->where('status', 'active')->orderByDesc('created_at')->limit(200)->get();
        $from = $this->from($request);
        $ids = array_values($links->pluck('id')->map(fn ($id) => (string) $id)->all());
        $scans = CaptureCounters::summary($ws->id, CaptureCounters::WA_LINK, $ids, $from);
        $chats = WhatsAppLinkHit::query()->whereIn('link_id', $ids)->where('created_at', '>=', $from)->groupBy('link_id')
            ->selectRaw('link_id, COUNT(*) AS chats, SUM(lead_id IS NOT NULL) AS leads')->get()->keyBy('link_id');

        return response()->json([
            'data' => $links->map(fn (WhatsAppLink $l) => CapturePresenter::waLink($ws, $l, [
                'scans' => (int) ($scans[$l->id]['views'] ?? 0),
                'chats' => (int) ($chats[$l->id]->chats ?? 0),
                'leads' => (int) ($chats[$l->id]->leads ?? 0),
            ]))->values(),
            'from' => $from,
        ]);
    }

    /** POST capture/whatsapp-links {name, message, channel_account_id?, settings?} */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $this->validated($request, true);
        $ws = $this->current->get();
        /** @var User $user */
        $user = $request->user();
        $link = WhatsAppLink::query()->create([
            'workspace_id' => $ws->id,
            'kind' => 'qr',
            'name' => $data['name'],
            'code' => WaCodes::generate('qr'),
            'message' => $data['message'],
            'channel_account_id' => $data['channel_account_id'] ?? null,
            'settings' => $data['settings'] ?? ['create_lead' => true, 'ask_consent' => true],
            'created_by' => $user->id,
        ]);
        $this->audit->handle('whatsapp_link.created', $link, ['name' => $link->name, 'code' => $link->code], $ws->id);

        return response()->json(CapturePresenter::waLink($ws, $link), 201);
    }

    /** GET capture/whatsapp-links/{link} */
    public function show(string $link): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(CapturePresenter::waLink($this->current->get(), $this->find($link)));
    }

    /** PATCH capture/whatsapp-links/{link}: name, message, channel_account_id, settings (el código no cambia) */
    public function update(Request $request, string $link): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $ws = $this->current->get();
        $model = $this->find($link);
        if ($model->kind !== 'qr' || $model->status !== 'active') {
            abort(409, __('Este chat se edita desde su página de captura.'));
        }
        $data = $this->validated($request, false);
        if (isset($data['settings'])) {
            $data['settings'] = array_replace((array) ($model->settings ?? []), $data['settings']);
        }
        $model->forceFill($data)->save();
        $this->audit->handle('whatsapp_link.updated', $model, ['fields' => array_keys($data)], $ws->id);

        return response()->json(CapturePresenter::waLink($ws, $model));
    }

    /** DELETE capture/whatsapp-links/{link}: se archiva (el QR impreso deja de abrir el chat; lo que llegó sigue contado). */
    public function destroy(string $link): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $ws = $this->current->get();
        $model = $this->find($link);
        abort_if($model->kind !== 'qr', 409, __('Este chat se edita desde su página de captura.'));
        $model->forceFill(['status' => 'archived'])->save();
        $this->audit->handle('whatsapp_link.archived', $model, ['code' => $model->code], $ws->id);

        return response()->json(['archived' => true]);
    }

    /** GET capture/whatsapp-links/{link}/stats?days=30: escaneos, chats, contactos nuevos, leads y consentimientos. */
    public function stats(Request $request, string $link): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $model = $this->find($link);
        $from = $this->from($request);
        $scans = CaptureCounters::read($ws->id, CaptureCounters::WA_LINK, $model->id, $from);
        $hits = WhatsAppLinkHit::query()->where('link_id', $model->id)->where('created_at', '>=', $from);

        return response()->json([
            'from' => $from,
            'totals' => [
                'scans' => $scans['totals']['views'],
                'unique_scans' => $scans['totals']['visitors'],
                'chats' => (clone $hits)->count(),
                'new_contacts' => (clone $hits)->where('was_new', true)->count(),
                'leads' => (clone $hits)->whereNotNull('lead_id')->count(),
                'consents' => (clone $hits)->whereNotNull('consent_granted_at')->count(),
            ],
            'daily' => array_map(fn (array $d) => ['day' => $d['day'], 'scans' => $d['views']], $scans['daily']),
        ]);
    }

    /** GET capture/whatsapp-links/{link}/qr?format=svg|png&size=&download=1 */
    public function qr(Request $request, string $link): Response
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $model = $this->find($link);

        return LandingPageController::qrResponse($request, CaptureUrls::waLinkQr($ws, $model), 'whatsapp-'.strtolower($model->code));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'message' => [$req, 'string', 'min:2', 'max:300'],
            'channel_account_id' => ['sometimes', 'nullable', 'string'],
            'settings' => ['sometimes', 'array'],
            'settings.create_lead' => ['sometimes', 'boolean'],
            'settings.ask_consent' => ['sometimes', 'boolean'],
            'settings.service' => ['sometimes', 'nullable', 'string', 'max:160'],
        ], [], ['message' => __('mensaje'), 'name' => __('nombre')]);
        $out = [];
        foreach (['name', 'message'] as $key) {
            if (isset($data[$key])) {
                $out[$key] = trim(strip_tags((string) $data[$key]));
            }
        }
        if (array_key_exists('channel_account_id', $data)) {
            $account = is_string($data['channel_account_id']) && $data['channel_account_id'] !== '' ? ChannelAccount::findByPublicId($data['channel_account_id']) : null;
            if ($data['channel_account_id'] !== null && $data['channel_account_id'] !== '' && ($account === null || $account->channel !== Channel::WhatsApp)) {
                throw ValidationException::withMessages(['channel_account_id' => __('Ese número de WhatsApp no está conectado en este espacio de trabajo.')]);
            }
            $out['channel_account_id'] = $account?->id;
        }
        if (isset($data['settings'])) {
            $out['settings'] = array_filter([
                'create_lead' => array_key_exists('create_lead', $data['settings']) ? (bool) $data['settings']['create_lead'] : null,
                'ask_consent' => array_key_exists('ask_consent', $data['settings']) ? (bool) $data['settings']['ask_consent'] : null,
                'service' => isset($data['settings']['service']) && is_string($data['settings']['service']) && trim($data['settings']['service']) !== '' ? trim($data['settings']['service']) : null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    private function find(string $publicId): WhatsAppLink
    {
        $link = WhatsAppLink::findByPublicId($publicId);
        abort_if($link === null, 404);

        return $link;
    }

    private function from(Request $request): string
    {
        $days = max(1, min(365, (int) ($request->query('days') ?? 30)));

        return CarbonImmutable::now()->utc()->subDays($days - 1)->toDateString();
    }
}
