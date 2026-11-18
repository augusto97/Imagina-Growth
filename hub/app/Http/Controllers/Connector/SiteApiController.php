<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductCategory;
use App\Domain\Sites\Actions\UpdateBackfillState;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints firmados del sitio (api-connector.md §5). VerifySiteSignature ya
 * autenticó la petición y activó el espacio de trabajo.
 */
final class SiteApiController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /v1/s/config */
    public function config(Request $request): JsonResponse
    {
        $site = $this->site($request);
        /** @var Workspace $ws */
        $ws = $this->current->get();

        return response()->json([
            'site_id' => $site->publicId(),
            'workspace' => [
                'id' => $ws->publicId(),
                'name' => $ws->name,
                'country' => $ws->country,
                'timezone' => $ws->timezone,
                'currency' => $ws->currency,
                'locale' => $ws->locale,
            ],
            'settings' => $site->effectiveSettings() + ['early_capture' => (bool) $ws->setting('early_capture', false)],
            'consent' => ['mode' => $ws->setting('consent_mode', 'strict'), 'texts' => []],
            'measurement' => $this->measurement(),
            'min_plugin_version' => config('igr.min_plugin_version'),
        ]);
    }

    /**
     * Píxeles del modo «completo» (api-connector.md §5.1). El ID del píxel es
     * público; los tokens de las APIs nunca salen del hub.
     *
     * @return array{mode: string, pixels: array<string, string>}
     */
    private function measurement(): array
    {
        $rows = DB::table('ad_destinations')
            ->where('workspace_id', $this->current->id())
            ->where('status', 'active')
            ->where('mode', 'full')
            ->whereNotNull('pixel_id')
            ->get(['platform', 'pixel_id']);
        $pixels = [];
        foreach ($rows as $row) {
            $pixels[(string) $row->platform] = (string) $row->pixel_id;
        }

        return ['mode' => $pixels === [] ? 'server_only' : 'full', 'pixels' => $pixels];
    }

    /** GET /v1/s/catalog/hashes?type=product|category&cursor=&limit= */
    public function catalogHashes(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $type = $request->query('type', 'product') === 'category' ? 'category' : 'product';
        $limit = min(5000, max(1, (int) $request->query('limit', 1000)));
        $cursor = (string) $request->query('cursor', '');

        $query = ($type === 'product' ? Product::query() : ProductCategory::query())
            ->where('site_id', $site->id)
            ->whereNull('deleted_at')
            ->when($cursor !== '', fn ($q) => $q->where('external_id', '>', $cursor))
            ->orderBy('external_id')
            ->limit($limit + 1);

        $rows = $query->get(['external_id', 'content_hash']);
        $next = null;
        if ($rows->count() > $limit) {
            $rows = $rows->take($limit);
            $next = (string) $rows->last()?->external_id;
        }

        return response()->json([
            'items' => $rows->map(fn ($r) => ['id' => (string) $r->external_id, 'hash' => (string) $r->content_hash])->values(),
            'next_cursor' => $next,
        ]);
    }

    /** PUT /v1/s/backfill */
    public function backfill(Request $request, UpdateBackfillState $update): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:queued,running,paused,completed,failed'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date'],
            'phase' => ['nullable', 'string', 'max:40'],
            'entities' => ['nullable', 'array', 'max:20'],
            'entities.*.total' => ['nullable', 'integer', 'min:0'],
            'entities.*.sent' => ['nullable', 'integer', 'min:0'],
            'reconciliation' => ['nullable', 'array'],
            'reconciliation.currency' => ['nullable', 'string', 'size:3'],
            'reconciliation.generated_at' => ['nullable', 'date'],
            'reconciliation.orders_by_status' => ['nullable', 'array', 'max:100'],
            'reconciliation.orders_by_status.*.count' => ['required_with:reconciliation.orders_by_status', 'integer', 'min:0'],
            'reconciliation.orders_by_status.*.total' => ['required_with:reconciliation.orders_by_status', 'regex:/^-?\d{1,16}(\.\d{1,6})?$/'],
            'reconciliation.refunds' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:1000'],
        ]);
        $update->handle($this->site($request), $data);

        return response()->json(['ok' => true]);
    }

    private function site(Request $request): Site
    {
        /** @var Site $site */
        $site = $request->attributes->get('igr_site');

        return $site;
    }
}
