<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa;

use App\Analytics\Ch;
use App\Domain\Sites\Actions\CreateConnectCode;
use App\Domain\Sites\Actions\DisconnectSite;
use App\Domain\Sites\Actions\RotateSiteSecret;
use App\Domain\Sites\Actions\SendSiteCommand;
use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Queries\ReconciliationQuery;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Http\Controllers\Spa\Concerns\Presenters;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

final class SiteController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /api/w/{workspace}/sites */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewSites');

        return response()->json(['sites' => Site::query()->orderBy('created_at')->get()->map(fn (Site $s) => Presenters::site($s))]);
    }

    /** GET /api/w/{workspace}/sites/{site} */
    public function show(string $site, ReconciliationQuery $reconciliation): JsonResponse
    {
        Gate::authorize('ws.viewSites');
        $model = $this->find($site);

        $rejections = DB::table('ingest_rejections')
            ->where('workspace_id', $model->workspace_id)->where('site_id', $model->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('id')->limit(20)
            ->get(['event_id', 'event', 'reason', 'detail', 'created_at']);

        $events24h = [];
        try {
            $events24h = Ch::forWorkspace($this->current->get())->select(
                'SELECT event, count() AS c FROM events WHERE workspace_id = {workspace_id:UInt32} AND site_id = {site:UInt32} AND received_at >= now() - INTERVAL 1 DAY GROUP BY event ORDER BY c DESC LIMIT 30',
                ['site' => $model->ch_id],
            );
        } catch (Throwable) {
            // ClickHouse caído: el panel sigue funcionando sin este bloque.
        }

        return response()->json([
            'site' => Presenters::site($model, detail: true),
            'reconciliation' => $reconciliation->handle($model),
            'rejections' => $rejections,
            'events_24h' => $events24h,
        ]);
    }

    /** POST /api/w/{workspace}/sites/connect-codes */
    public function connectCode(Request $request, CreateConnectCode $create): JsonResponse
    {
        Gate::authorize('ws.manageSites');
        $data = $request->validate([
            'site_url' => ['nullable', 'url', 'max:2048', 'required_with:return_url,nonce'],
            'nonce' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            'return_url' => ['nullable', 'url', 'max:2048'],
            'platform' => ['nullable', 'in:woocommerce,wordpress'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json($create->handle($this->current->get(), $user, $data), 201);
    }

    /** POST /api/w/{workspace}/sites/{site}/rotate-secret */
    public function rotate(string $site, RotateSiteSecret $rotate): JsonResponse
    {
        Gate::authorize('ws.manageSites');
        try {
            $rotate->handle($this->find($site));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/w/{workspace}/sites/{site}/disconnect */
    public function disconnect(string $site, DisconnectSite $disconnect): JsonResponse
    {
        Gate::authorize('ws.manageSites');
        $disconnect->handle($this->find($site));

        return response()->json(['ok' => true]);
    }

    /** POST /api/w/{workspace}/sites/{site}/commands/{command} — comandos permitidos desde el panel en la Fase 0. */
    public function command(Request $request, string $site, string $command, SendSiteCommand $send, RecordAuditLog $audit): JsonResponse
    {
        $allowed = ['site.diagnostics' => 'ws.viewSites', 'catalog.resync' => 'ws.manageSites', 'backfill.start' => 'ws.manageSites'];
        if (! isset($allowed[$command])) {
            return response()->json(['message' => __('Comando no disponible.')], 404);
        }
        Gate::authorize($allowed[$command]);
        $model = $this->find($site);
        $args = $command === 'backfill.start' ? $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'restart' => ['nullable', 'boolean'],
        ]) : [];
        try {
            $data = $send->handle($model, $command, $args);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        if ($command !== 'site.diagnostics') {
            $audit->handle('site.command', $model, ['command' => $command] + $args, $model->workspace_id);
        }

        return response()->json(['data' => $data]);
    }

    private function find(string $publicId): Site
    {
        return Site::findByPublicId($publicId) ?? abort(404);
    }
}
