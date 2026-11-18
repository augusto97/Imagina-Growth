<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Http\Api;

use App\Domain\Recommendations\Banners\ResolveBanner;
use App\Domain\Recommendations\Models\OnsiteBanner;
use App\Domain\Segments\Models\Segment;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Banners personalizados por segmento (M16). Ver: viewDashboards. Crear,
 * cambiar o borrar: manageMarketing. El texto sin sesión (`text_public`) no
 * puede llevar {{nombre}} ni {{puntos}}: esos datos solo se muestran con sesión.
 */
final class BannersController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET /api/w/{ws}/onsite-commerce/banners */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $banners = OnsiteBanner::query()->orderBy('placement')->orderBy('priority')->orderBy('created_at')->get();
        $segments = Segment::query()->orderByDesc('is_system')->orderBy('name')->get(['id', 'name', 'member_count']);
        $names = $segments->pluck('name', 'id');

        return response()->json([
            'data' => $banners->map(fn (OnsiteBanner $b) => self::present($b, $b->segment_id !== null ? ($names[$b->segment_id] ?? null) : null))->values(),
            'segments' => $segments->map(fn (Segment $s) => ['id' => $s->publicId(), 'name' => $s->name, 'members' => (int) $s->member_count])->values(),
            'sites' => Site::query()->orderBy('created_at')->get(['id', 'name'])->map(fn (Site $s) => ['id' => $s->publicId(), 'name' => $s->name])->values(),
            'variables' => ['private' => ResolveBanner::PRIVATE_VARIABLES, 'public' => ['carrito_items']],
        ]);
    }

    /** POST /api/w/{ws}/onsite-commerce/banners */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $this->validated($request, null);
        $banner = OnsiteBanner::query()->create($data + ['workspace_id' => $this->current->id(), 'created_by' => $request->user()?->getAuthIdentifier()]);
        $this->audit->handle('onsite_commerce.banner_created', $banner, ['name' => $banner->name, 'audience' => $banner->audience], $this->current->id());

        return response()->json(self::present($banner, $this->segmentName($banner)), 201);
    }

    /** PATCH /api/w/{ws}/onsite-commerce/banners/{banner} */
    public function update(Request $request, string $banner): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = OnsiteBanner::findByPublicId($banner) ?? abort(404);
        $data = $this->validated($request, $model);
        $model->fill($data)->save();
        $this->audit->handle('onsite_commerce.banner_updated', $model, ['fields' => array_keys($data)], $this->current->id());

        return response()->json(self::present($model, $this->segmentName($model)));
    }

    /** DELETE /api/w/{ws}/onsite-commerce/banners/{banner} */
    public function destroy(string $banner): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = OnsiteBanner::findByPublicId($banner) ?? abort(404);
        $model->delete();
        $this->audit->handle('onsite_commerce.banner_deleted', $model, ['name' => $model->name], $this->current->id());

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?OnsiteBanner $banner): array
    {
        $creating = $banner === null;
        $req = fn (string $rule) => $creating ? ['required', $rule] : ['sometimes', $rule];
        $data = $request->validate([
            'name' => [...$req('string'), 'max:120'],
            'placement' => ['sometimes', Rule::in(OnsiteBanner::PLACEMENTS)],
            'audience' => [...$req('string'), Rule::in(OnsiteBanner::AUDIENCES)],
            'segment_id' => ['nullable', 'string', 'max:40'],
            'min_points' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'text' => [...$req('string'), 'max:300'],
            'text_public' => ['nullable', 'string', 'max:300'],
            'cta' => ['nullable', 'string', 'max:60'],
            'url' => ['nullable', 'url:https,http', 'max:500'],
            'style' => ['sometimes', Rule::in(OnsiteBanner::STYLES)],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
            'site_id' => ['nullable', 'string', 'max:40'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        $audience = $data['audience'] ?? $banner?->audience;
        if (array_key_exists('segment_id', $data) || $audience === 'segment') {
            $segmentId = $data['segment_id'] ?? ($creating ? null : $banner->segment_id);
            $segment = is_string($segmentId) && $segmentId !== '' ? (Segment::findByPublicId($segmentId) ?? Segment::query()->find($segmentId)) : null;
            if ($audience === 'segment' && $segment === null) {
                throw ValidationException::withMessages(['segment_id' => __('Elige un segmento de este espacio de trabajo.')]);
            }
            $data['segment_id'] = $audience === 'segment' ? $segment?->id : null;
        }
        if (array_key_exists('site_id', $data)) {
            $site = is_string($data['site_id']) && $data['site_id'] !== '' ? Site::findByPublicId($data['site_id']) : null;
            if ($data['site_id'] !== null && $data['site_id'] !== '' && $site === null) {
                throw ValidationException::withMessages(['site_id' => __('Ese sitio no existe.')]);
            }
            $data['site_id'] = $site?->id;
        }
        $public = $data['text_public'] ?? null;
        if (is_string($public) && preg_match('/\{\{\s*('.implode('|', ResolveBanner::PRIVATE_VARIABLES).')\s*\}\}/', $public) === 1) {
            throw ValidationException::withMessages(['text_public' => __('El texto sin sesión no puede llevar el nombre ni los puntos: se ve en páginas que no saben quién es la persona.')]);
        }

        return $data;
    }

    private function segmentName(OnsiteBanner $banner): ?string
    {
        return $banner->segment_id !== null ? Segment::query()->whereKey($banner->segment_id)->value('name') : null;
    }

    /** @return array<string, mixed> */
    public static function present(OnsiteBanner $b, ?string $segmentName): array
    {
        return [
            'id' => $b->publicId(),
            'name' => $b->name,
            'placement' => $b->placement,
            'audience' => $b->audience,
            'segment' => $b->segment_id !== null ? ['id' => Segment::ID_PREFIX.'_'.$b->segment_id, 'name' => $segmentName] : null,
            'min_points' => $b->min_points,
            'text' => $b->text,
            'text_public' => $b->text_public,
            'cta' => $b->cta,
            'url' => $b->url,
            'style' => $b->style,
            'priority' => $b->priority,
            'status' => $b->status,
            'site_id' => $b->site_id !== null ? Site::ID_PREFIX.'_'.$b->site_id : null,
            'starts_at' => $b->starts_at?->toIso8601String(),
            'ends_at' => $b->ends_at?->toIso8601String(),
            'live' => $b->isLive(),
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }
}
