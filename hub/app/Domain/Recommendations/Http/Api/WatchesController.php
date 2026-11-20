<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Http\Api;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Recommendations\Models\CommerceSettings;
use App\Domain\Recommendations\Models\ProductWatch;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «Avísame» en el panel (M16): suscripciones activas, avisos enviados y las
 * ventas de quienes compraron el producto en los 7 días siguientes al aviso.
 * Ver: viewDashboards. Cambiar ajustes o cancelar: manageMarketing.
 */
final class WatchesController
{
    /** Días después del aviso en los que una compra del producto cuenta como venta del aviso. */
    public const SALE_WINDOW_DAYS = 7;

    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET /api/w/{ws}/onsite-commerce/watches/summary?days=90 */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $days = max(7, min((int) $request->query('days', '90'), 365));
        $ws = $this->current->id();
        $now = CarbonImmutable::now();
        $from = $now->subDays($days)->startOfDay();
        $base = DB::table('product_watches')->where('workspace_id', $ws);

        $byStatus = (clone $base)->groupBy('status', 'kind')->selectRaw('status, kind, COUNT(*) AS n')->get();
        $count = fn (string $status, ?string $kind = null) => (int) $byStatus->filter(fn ($r) => $r->status === $status && ($kind === null || $r->kind === $kind))->sum('n');
        $notifiedInRange = (clone $base)->where('status', 'notified')->where('notified_at', '>=', $from)->count();
        $sales = $this->sales($ws, $from);

        // Serie semanal (lunes a domingo) de suscripciones y avisos enviados.
        $weeks = [];
        $start = $from->startOfWeek(CarbonImmutable::MONDAY);
        for ($w = $start; $w->lte($now); $w = $w->addWeek()) {
            $weeks[$w->toDateString()] = ['week' => $w->toDateString(), 'created' => 0, 'notified' => 0];
        }
        foreach ((clone $base)->where('created_at', '>=', $start)->get(['created_at']) as $r) {
            $key = CarbonImmutable::parse((string) $r->created_at)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            if (isset($weeks[$key])) {
                $weeks[$key]['created']++;
            }
        }
        foreach ((clone $base)->where('status', 'notified')->where('notified_at', '>=', $start)->get(['notified_at']) as $r) {
            $key = CarbonImmutable::parse((string) $r->notified_at)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            if (isset($weeks[$key])) {
                $weeks[$key]['notified']++;
            }
        }

        $top = (clone $base)->where('status', 'active')
            ->groupBy('site_id', 'external_product_id')
            ->selectRaw("site_id, external_product_id, SUM(kind = 'back_in_stock') AS back_in_stock, SUM(kind = 'price_drop') AS price_drop, COUNT(*) AS n")
            ->orderByDesc('n')->limit(10)->get();
        $products = DB::table('products')->where('workspace_id', $ws)->whereIn('external_id', $top->pluck('external_product_id')->all())
            ->get(['site_id', 'external_id', 'name', 'image_url', 'stock_status', 'price'])->keyBy(fn ($p) => $p->site_id.'|'.$p->external_id);

        return response()->json([
            'days' => $days,
            'currency' => $this->current->get()->currency ?: 'COP',
            'totals' => [
                'active' => $count('active'),
                'active_back_in_stock' => $count('active', 'back_in_stock'),
                'active_price_drop' => $count('active', 'price_drop'),
                'notified' => $count('notified'),
                'notified_in_range' => $notifiedInRange,
                'cancelled' => $count('cancelled'),
                'orders_after' => $sales['orders'],
                'revenue_after' => $sales['revenue'],
                'buyers_after' => $sales['buyers'],
                'conversion_rate' => $notifiedInRange > 0 ? round($sales['buyers'] / $notifiedInRange, 4) : null,
            ],
            'weekly' => array_values($weeks),
            'top_products' => $top->map(function ($r) use ($products) {
                $p = $products[$r->site_id.'|'.$r->external_product_id] ?? null;

                return [
                    'id' => (string) $r->external_product_id,
                    'name' => $p->name ?? null,
                    'image' => $p->image_url ?? null,
                    'in_stock' => $p !== null && in_array($p->stock_status, [null, 'instock', 'onbackorder'], true),
                    'price' => $p !== null ? number_format((float) $p->price, 2, '.', '') : null,
                    'back_in_stock' => (int) $r->back_in_stock,
                    'price_drop' => (int) $r->price_drop,
                ];
            })->values(),
            'sale_window_days' => self::SALE_WINDOW_DAYS,
            'settings' => $this->settingsPayload(),
        ]);
    }

    /** GET /api/w/{ws}/onsite-commerce/watches?status=&kind=&page= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'notified', 'cancelled'])],
            'kind' => ['nullable', Rule::in(ProductWatch::KINDS)],
            'product_id' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $perPage = 25;
        $page = (int) ($data['page'] ?? 1);
        $query = ProductWatch::query()
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['kind']), fn ($q) => $q->where('kind', $data['kind']))
            ->when(! empty($data['product_id']), fn ($q) => $q->where('external_product_id', $data['product_id']));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $products = DB::table('products')->where('workspace_id', $this->current->id())->whereIn('external_id', $rows->pluck('external_product_id')->unique()->all())
            ->get(['site_id', 'external_id', 'name', 'image_url', 'stock_status'])->keyBy(fn ($p) => $p->site_id.'|'.$p->external_id);
        $canSeeContacts = Gate::allows('ws.viewAllContacts');
        $contacts = $canSeeContacts ? Contact::query()->whereIn('id', $rows->pluck('contact_id')->unique()->all())->get(['id', 'first_name', 'last_name'])->keyBy('id') : collect();

        return response()->json([
            'data' => $rows->map(function (ProductWatch $w) use ($products, $contacts, $canSeeContacts) {
                $p = $products[$w->site_id.'|'.$w->external_product_id] ?? null;
                $c = $contacts[$w->contact_id] ?? null;

                return [
                    'id' => $w->publicId(),
                    'kind' => $w->kind,
                    'status' => $w->status,
                    'channel' => $w->channel,
                    'product' => ['id' => $w->external_product_id, 'name' => $p->name ?? null, 'image' => $p->image_url ?? null,
                        'in_stock' => $p !== null && in_array($p->stock_status, [null, 'instock', 'onbackorder'], true)],
                    'contact' => $canSeeContacts ? ['id' => Contact::ID_PREFIX.'_'.$w->contact_id, 'name' => $c instanceof Contact ? ($c->displayName() ?: null) : null] : null,
                    'threshold' => $w->threshold,
                    'notified_price' => $w->notified_price,
                    'created_at' => $w->created_at?->toIso8601String(),
                    'notified_at' => $w->notified_at?->toIso8601String(),
                    'next_attempt_at' => $w->next_attempt_at?->toIso8601String(),
                    'last_reason' => $w->last_reason,
                ];
            })->values(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ]);
    }

    /** PATCH /api/w/{ws}/onsite-commerce/watches/settings */
    public function updateSettings(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => [Rule::in(['whatsapp', 'email', 'sms'])],
            'templates' => ['sometimes', 'array'],
            'templates.*' => ['array'],
            'templates.*.*' => ['nullable', 'string', 'max:40'],
        ]);
        $model = CommerceSettings::current();
        $current = $model->watchSettings();
        $templates = $current['templates'];
        foreach ((array) ($data['templates'] ?? []) as $kind => $byChannel) {
            if (! in_array($kind, ProductWatch::KINDS, true)) {
                throw ValidationException::withMessages(['templates' => __('Tipo de aviso desconocido.')]);
            }
            foreach ((array) $byChannel as $channel => $templateId) {
                if (! in_array($channel, ['email', 'whatsapp', 'sms'], true)) {
                    throw ValidationException::withMessages(['templates' => __('Canal desconocido.')]);
                }
                if ($templateId !== null && $templateId !== '') {
                    $template = Template::findByPublicId((string) $templateId);
                    if ($template === null || $template->channel !== $channel) {
                        throw ValidationException::withMessages(["templates.{$kind}.{$channel}" => __('Elige una plantilla de :channel de este espacio de trabajo.', ['channel' => $channel])]);
                    }
                    $templateId = $template->publicId();
                }
                $templates[$kind][$channel] = $templateId ?: null;
            }
        }
        $model->watches = [
            'enabled' => (bool) ($data['enabled'] ?? $current['enabled']),
            'channels' => array_values(array_unique((array) ($data['channels'] ?? $current['channels']))),
            'templates' => $templates,
        ];
        $model->save();
        $this->audit->handle('onsite_commerce.watches_updated', $model, ['enabled' => $model->watches['enabled'], 'channels' => $model->watches['channels']], $this->current->id());

        return response()->json(['settings' => $this->settingsPayload()]);
    }

    /** POST /api/w/{ws}/onsite-commerce/watches/{watch}/cancel */
    public function cancel(string $watch): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = ProductWatch::findByPublicId($watch);
        if ($model === null) {
            abort(404);
        }
        if ($model->status === ProductWatch::ACTIVE) {
            $model->forceFill(['status' => ProductWatch::CANCELLED, 'last_reason' => 'cancelled_by_team'])->save();
            $this->audit->handle('onsite_commerce.watch_cancelled', $model, [], $this->current->id());
        }

        return response()->json(['id' => $model->publicId(), 'status' => $model->status]);
    }

    /**
     * Ventas después del aviso: pedidos pagados de la misma persona que incluyen
     * el producto, dentro de los 7 días siguientes.
     *
     * @return array{orders: int, buyers: int, revenue: string}
     */
    private function sales(string $workspaceId, CarbonImmutable $from): array
    {
        $rows = DB::table('product_watches as w')
            ->join('orders as o', function ($j): void {
                $j->on('o.workspace_id', '=', 'w.workspace_id')->on('o.contact_id', '=', 'w.contact_id')->on('o.site_id', '=', 'w.site_id')
                    ->on('o.created_at', '>=', 'w.notified_at')->where('o.is_paid', true)
                    ->whereRaw('o.created_at < DATE_ADD(w.notified_at, INTERVAL '.self::SALE_WINDOW_DAYS.' DAY)');
            })
            ->join('order_items as i', fn ($j) => $j->on('i.order_id', '=', 'o.id')->on('i.external_product_id', '=', 'w.external_product_id'))
            ->where('w.workspace_id', $workspaceId)->where('w.status', 'notified')->where('w.notified_at', '>=', $from)
            ->distinct()->get(['o.id', 'o.contact_id', 'o.total_ws', 'o.total']);
        $orders = $rows->unique('id');

        return [
            'orders' => $orders->count(),
            'buyers' => $orders->pluck('contact_id')->unique()->count(),
            'revenue' => number_format((float) $orders->sum(fn ($o) => (float) ($o->total_ws ?? $o->total)), 2, '.', ''),
        ];
    }

    /** @return array<string, mixed> */
    private function settingsPayload(): array
    {
        $settings = CommerceSettings::current()->watchSettings();
        $templates = Template::query()->whereIn('channel', ['email', 'whatsapp', 'sms'])->where('status', '!=', 'archived')
            ->orderBy('name')->limit(300)->get(['id', 'name', 'channel', 'category', 'library_key', 'wa_template_id']);

        return $settings + [
            'library' => config('igr-commerce.watches.library_templates'),
            'available_templates' => $templates->map(fn (Template $t) => [
                'id' => $t->publicId(), 'name' => $t->name, 'channel' => $t->channel, 'category' => $t->category, 'library_key' => $t->library_key,
            ])->values(),
        ];
    }
}
