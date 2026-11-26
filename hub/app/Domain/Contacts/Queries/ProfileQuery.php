<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Queries;

use App\Analytics\Ch;
use App\Domain\Commerce\Models\Order;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Intelligence\Predictions\Support\Reliability;
use App\Domain\Privacy\Actions\ExportContactData;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Datos del perfil 360 (modulos.md M03). Cada bloque es una consulta acotada
 * para que el perfil cargue en menos de 800 ms con 2 años de historial.
 */
final class ProfileQuery
{
    /** Grupos de la línea de tiempo (filtros de la pantalla). */
    public const TIMELINE_GROUPS = [
        'navegacion' => ['page.viewed', 'page.engaged', 'product.viewed', 'category.viewed', 'search.performed', 'cta.clicked', 'identify'],
        'carrito' => ['cart.item_added', 'cart.item_removed', 'cart.updated', 'checkout.started', 'coupon.applied', 'cart.abandoned'],
        'pedidos' => ['order.created', 'order.paid', 'order.status_changed', 'order.completed', 'order.cancelled', 'order.failed', 'order.refunded', 'order.payment_pending'],
        'fidelizacion' => ['points.earned', 'points.redeemed', 'points.expired', 'tier.changed', 'wallet.credited', 'wallet.debited', 'referral.shared', 'referral.converted'],
        'cuenta' => ['customer.registered', 'customer.updated', 'customer.logged_in', 'form.submitted'],
        'sitio' => ['widget.submitted', 'product_watch.created', 'product_watch.notified'],
        'mensajes' => ['message.sent', 'message.delivered', 'message.opened', 'message.read', 'message.clicked', 'message.replied', 'message.failed', 'message.bounced', 'message.complained', 'message.unsubscribed', 'whatsapp.inbound', 'flow.entered', 'flow.exited'],
        'resenas' => ['review.submitted', 'review.requested', 'review.collected'],
        'encuestas' => ['survey.answered', 'nps.answered'],
        'consentimiento' => ['consent.granted', 'consent.revoked'],
        'segmentos' => ['segment.entered', 'segment.exited'],
        // Negocios de servicios: su lead, sus citas y sus cotizaciones (eventos del hub, eventos.md §4.6).
        'servicios' => ['lead.created', 'lead.stage_changed', 'lead.won', 'lead.lost', 'appointment.booked', 'appointment.confirmed', 'appointment.rescheduled',
            'appointment.cancelled', 'appointment.completed', 'appointment.no_show', 'quote.requested', 'quote.sent'],
    ];

    /** @return array<string, mixed> */
    public function header(Workspace $workspace, Contact $contact): array
    {
        $consents = ContactConsent::query()->whereIn('contact_id', ExportContactData::contactIds($contact))->get()
            ->mapWithKeys(fn (ContactConsent $c) => [$c->channel.'.'.$c->purpose => [
                'status' => $c->status, 'source' => $c->source, 'version' => $c->legal_text_version,
                'at' => $c->lastActionAt()?->toIso8601String(),
            ]]);
        $merged = Contact::query()->where('merged_into_id', $contact->id)->count();
        $monthly = Order::query()->where('contact_id', $contact->id)->where('is_paid', true)
            ->where('created_at', '>=', now()->subMonths(24)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS orders, COALESCE(SUM(total_ws), 0) AS revenue")
            ->groupBy('month')->orderBy('month')->get()
            ->map(fn ($r) => ['month' => $r->getAttribute('month'), 'orders' => (int) $r->getAttribute('orders'), 'revenue' => (string) $r->getAttribute('revenue')]);

        $ordersCount = (int) $contact->getAttribute('orders_count');
        // La conversación más reciente de la bandeja (el botón «Escribir» del perfil abre ese chat).
        $conversation = DB::table('conversations as c')->join('channel_accounts as a', 'a.id', '=', 'c.channel_account_id')
            ->where('c.workspace_id', $workspace->id)->whereIn('c.contact_id', ExportContactData::contactIds($contact))
            ->orderByDesc('c.last_message_at')->first(['c.id', 'a.channel', 'c.last_message_at']);
        // Predicciones (Fase 4): el motivo de «Datos insuficientes» y la confiabilidad salen de la última corrida de cada modelo.
        $runs = PredictionRun::latestByModel($workspace->id);
        $hasOrders = (int) $contact->getAttribute('orders_count') > 0;
        $reason = function (string $model) use ($runs, $hasOrders): string {
            $run = $runs[$model] ?? null;
            $why = match (true) {
                ! $hasOrders => __('todavía no ha comprado.'),
                $run === null => __('las predicciones todavía no se han calculado para este espacio de trabajo.'),
                default => lcfirst((string) ($run->metrics['reason'] ?? __('sin predicción para este contacto en la última corrida.'))),
            };

            return __('Datos insuficientes: :why', ['why' => $why]);
        };
        $insufficient = $reason('clv');

        return [
            'id' => $contact->publicId(),
            'name' => $contact->displayName(),
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'email' => ContactAccess::email($contact->email),
            'phone' => ContactAccess::phone($contact->phone),
            'pii_visible' => ContactAccess::canSeePii(),
            'city' => $contact->getAttribute('city'),
            'region_code' => $contact->getAttribute('region_code'),
            'country' => $contact->getAttribute('country'),
            'birthday' => $contact->getAttribute('birthday')?->toDateString(),
            'renewal_at' => $contact->getAttribute('renewal_at')?->toDateString(),
            'lifecycle_stage' => $contact->getAttribute('lifecycle_stage'),
            'tags' => $contact->getAttribute('tags') ?? [],
            'custom_attributes' => $contact->getAttribute('custom_attributes') ?? (object) [],
            'hdyhau' => $contact->getAttribute('hdyhau'),
            'first_source' => $contact->getAttribute('first_source'),
            'first_order_source' => $contact->getAttribute('first_order_source'),
            'rfm' => $contact->getAttribute('rfm_segment') === null ? null : [
                'segment' => $contact->getAttribute('rfm_segment'),
                'r' => $contact->getAttribute('rfm_r'), 'f' => $contact->getAttribute('rfm_f'), 'm' => $contact->getAttribute('rfm_m'),
                'computed_at' => $contact->getAttribute('rfm_computed_at')?->toIso8601String(),
            ],
            'clv' => [
                'historic' => (string) $contact->getAttribute('revenue_total_ws'),
                'predicted_12m' => $contact->getAttribute('predicted_clv_12m_ws'),
                'predicted_reason' => $contact->getAttribute('predicted_clv_12m_ws') === null ? $insufficient : null,
            ],
            'churn' => $contact->getAttribute('churn_band') === null ? ['band' => null, 'reason' => $reason('churn')] : ['band' => $contact->getAttribute('churn_band'), 'risk' => $contact->getAttribute('churn_risk')],
            'expected_next_order_at' => $contact->getAttribute('expected_next_order_at')?->toIso8601String(),
            'predictions' => [
                'p_alive' => $contact->getAttribute('p_alive') !== null ? (float) $contact->getAttribute('p_alive') : null,
                'expected_orders_90d' => $contact->getAttribute('expected_orders_90d') !== null ? (float) $contact->getAttribute('expected_orders_90d') : null,
                'next_order_reason' => $contact->getAttribute('expected_next_order_at') === null ? $reason('edno') : null,
                'method' => ['churn' => ($runs['churn'] ?? null)?->status === 'heuristic' ? 'heuristic' : 'model'],
                'reliability' => [
                    'clv' => Reliability::level($runs['clv'] ?? null),
                    'churn' => Reliability::level($runs['churn'] ?? null),
                    'edno' => Reliability::level($runs['edno'] ?? null),
                ],
                'engagement_score' => $contact->getAttribute('engagement_score') !== null ? (int) $contact->getAttribute('engagement_score') : null,
                'preferred_hour' => $contact->getAttribute('preferred_hour') !== null ? (int) $contact->getAttribute('preferred_hour') : null,
                'preferred_channel' => $contact->getAttribute('preferred_channel'),
                'price_band' => $contact->getAttribute('price_band'),
            ],
            'consents' => $consents,
            'stats' => [
                'orders_count' => $ordersCount,
                'revenue_total_ws' => (string) $contact->getAttribute('revenue_total_ws'),
                'aov_ws' => (string) $contact->getAttribute('aov_ws'),
                'first_order_at' => $contact->getAttribute('first_order_at')?->toIso8601String(),
                'last_order_at' => $contact->getAttribute('last_order_at')?->toIso8601String(),
                'avg_days_between_orders' => $contact->getAttribute('avg_days_between_orders'),
                'refunds_count' => (int) $contact->getAttribute('refunds_count'),
                'coupon_orders_ratio' => $contact->getAttribute('coupon_orders_ratio'),
            ],
            'monthly' => $monthly,
            'merged_count' => $merged,
            'merged_into' => self::survivor($contact)?->publicId(),
            'first_seen_at' => $contact->first_seen_at?->toIso8601String(),
            'last_seen_at' => $contact->last_seen_at?->toIso8601String(),
            'currency' => $workspace->currency,
            'conversation' => $conversation === null ? null : [
                'id' => 'cnv_'.$conversation->id,
                'channel' => (string) $conversation->channel,
                'last_message_at' => $conversation->last_message_at !== null ? Carbon::parse((string) $conversation->last_message_at)->toIso8601String() : null,
            ],
        ];
    }

    /**
     * @param  list<string>  $groups
     * @return array{data: list<array<string, mixed>>, next_before: string|null}
     */
    public function timeline(Workspace $workspace, Contact $contact, array $groups, ?string $before, int $limit = 50): array
    {
        $ids = ExportContactData::contactIds($contact);
        $anonymous = array_values(ContactIdentity::query()->whereIn('contact_id', $ids)->where('type', 'anonymous_id')->pluck('value')->map(fn ($v) => (string) $v)->all());
        $events = [];
        foreach ($groups === [] ? array_keys(self::TIMELINE_GROUPS) : $groups as $group) {
            array_push($events, ...(self::TIMELINE_GROUPS[$group] ?? []));
        }
        if ($events === []) {
            return ['data' => [], 'next_before' => null];
        }

        // En dos pasos (docs/rendimiento.md §2): primero qué eventos van, leyendo solo columnas livianas; después el
        // detalle de esos eventos por la clave primaria (evento y rango de fechas). En una sola consulta ClickHouse leía
        // `properties` (JSON) de cada bloque con algún evento del contacto: ~480 MB y ~235 ms para un cliente con
        // dos años de historia, contra ~115 MB y ~120 ms así.
        $ch = Ch::forWorkspace($workspace);
        $picked = $ch->select(
            "SELECT toString(event_id) AS event_id, event, formatDateTime(occurred_at, '%Y-%m-%dT%H:%i:%S.%fZ', 'UTC') AS occurred_iso
             FROM events
             WHERE workspace_id = {workspace_id:UInt32}
               AND (has({ids:Array(String)}, contact_id) OR has({anon:Array(String)}, anonymous_id))
               AND event IN {events:Array(String)}
               AND occurred_at < parseDateTime64BestEffort({before:String}, 3)
             ORDER BY occurred_at DESC
             LIMIT {limit:UInt32}",
            [
                'ids' => ExportContactData::chArray($ids),
                'anon' => ExportContactData::chArray($anonymous),
                'events' => ExportContactData::chArray($events),
                'before' => $before ?? now()->addMinute()->utc()->toIso8601String(),
                'limit' => $limit + 1,
            ],
        );
        $rows = $picked === [] ? [] : $ch->select(
            "SELECT toString(event_id) AS event_id, event, formatDateTime(occurred_at, '%Y-%m-%dT%H:%i:%S.%fZ', 'UTC') AS occurred_iso,
                    source_type, page_type, path, channel, device_type, city, product_id, order_id,
                    toString(revenue) AS revenue, currency, toJSONString(properties) AS properties
             FROM events
             WHERE workspace_id = {workspace_id:UInt32}
               AND event IN {events:Array(String)}
               AND occurred_at >= parseDateTime64BestEffort({from:String}, 3)
               AND occurred_at <= parseDateTime64BestEffort({to:String}, 3)
               AND has({event_ids:Array(String)}, toString(event_id))
             ORDER BY occurred_at DESC
             LIMIT {limit:UInt32}",
            [
                'events' => ExportContactData::chArray(array_values(array_unique(array_map(fn (array $r) => (string) $r['event'], $picked)))),
                'from' => (string) end($picked)['occurred_iso'],
                'to' => (string) $picked[0]['occurred_iso'],
                'event_ids' => ExportContactData::chArray(array_map(fn (array $r) => (string) $r['event_id'], $picked)),
                'limit' => $limit + 1,
            ],
        );

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $data = array_map(function (array $r): array {
            $props = json_decode((string) $r['properties'], true) ?: [];

            return [
                'id' => $r['event_id'],
                'event' => $r['event'],
                'occurred_at' => $r['occurred_iso'],
                'source' => $r['source_type'],
                'channel' => $r['channel'] ?: null,
                'device' => $r['device_type'] ?: null,
                'path' => $r['path'] ?: null,
                'summary' => self::summary((string) $r['event'], $props, $r),
            ];
        }, $rows);

        return ['data' => $data, 'next_before' => $more && $data !== [] ? (string) end($data)['occurred_at'] : null];
    }

    /**
     * Lo esencial de cada evento para la línea de tiempo (sin PII: viene de ClickHouse ya limpio).
     *
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function summary(string $event, array $p, array $row): array
    {
        $order = is_array($p['order'] ?? null) ? $p['order'] : null;

        return array_filter([
            'product' => $p['name'] ?? null,
            'product_id' => $p['product_id'] ?? ($row['product_id'] ?: null),
            'category' => str_starts_with($event, 'category.') ? ($p['name'] ?? null) : null,
            'query' => $p['query'] ?? null,
            'results' => $p['results_count'] ?? null,
            'cta' => isset($p['kind']) && $event === 'cta.clicked' ? $p['kind'] : null,
            'order_number' => $order['number'] ?? ($p['order_id'] ?? null),
            'status' => $order['status'] ?? ($p['to'] ?? null),
            'total' => $order['totals']['total'] ?? ($p['amount'] ?? null),
            'currency' => $order['currency'] ?? ($row['currency'] ?: null),
            'items' => $order !== null ? count((array) ($order['items'] ?? [])) : null,
            'payment' => $order['payment_method']['title'] ?? ($p['payment_method'] ?? null),
            'form' => $p['form_name'] ?? null,
            'rating' => $p['rating'] ?? null,
            'answer' => $p['answer'] ?? null,
            'channel' => str_starts_with($event, 'consent.') ? ($p['channel'] ?? null) : null,
            'segment' => $p['segment_name'] ?? null,
            'cart_total' => $p['subtotal'] ?? ($p['cart_total'] ?? null),
            // Fases 2 y 3: mensajes, flujos, fidelización, referidos, reseñas, NPS y elementos del sitio.
            'points' => str_starts_with($event, 'points.') ? ($p['points'] ?? null) : null,
            'wallet' => str_starts_with($event, 'wallet.') ? ($p['amount'] ?? null) : null,
            'reason' => str_starts_with($event, 'points.') || str_starts_with($event, 'wallet.') ? ($p['reason'] ?? null) : null,
            'tier' => $event === 'tier.changed' ? ($p['to_name'] ?? ($p['to'] ?? null)) : null,
            'direction' => $event === 'tier.changed' ? ($p['direction'] ?? null) : null,
            'referral_code' => str_starts_with($event, 'referral.') ? ($p['referral_code'] ?? null) : null,
            'score' => $event === 'nps.answered' ? ($p['score'] ?? null) : null,
            'nps_category' => $event === 'nps.answered' ? ($p['category'] ?? null) : null,
            'media' => $event === 'review.collected' && ($p['has_media'] ?? false) ? ($p['media_count'] ?? 1) : null,
            'widget_kind' => $event === 'widget.submitted' ? ($p['kind'] ?? null) : null,
            'watch_kind' => str_starts_with($event, 'product_watch.') ? ($p['kind'] ?? null) : null,
            'message_channel' => str_starts_with($event, 'message.') ? ($p['channel'] ?? null) : null,
            'flow' => str_starts_with($event, 'flow.') ? ($p['flow_name'] ?? null) : null,
            // Servicios: el servicio del lead o de la cita, la hora de la cita, el valor ganado y la cotización.
            'service' => str_starts_with($event, 'lead.') || str_starts_with($event, 'appointment.') ? ($p['service_name'] ?? ($p['service'] ?? null)) : null,
            'appointment_at' => str_starts_with($event, 'appointment.') ? ($p['starts_at'] ?? null) : null,
            'lead_value' => $event === 'lead.won' ? ($p['value'] ?? null) : null,
            'calculator' => str_starts_with($event, 'quote.') ? ($p['calculator_name'] ?? null) : null,
            'estimate_min' => $event === 'quote.requested' ? ($p['estimate_min'] ?? null) : null,
            'estimate_max' => $event === 'quote.requested' ? ($p['estimate_max'] ?? null) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @return array<string, mixed> */
    public function orders(Contact $contact, int $page = 1): array
    {
        $paginator = Order::query()->whereIn('contact_id', ExportContactData::contactIds($contact))->with('items')
            ->orderByDesc('created_at')->paginate(20, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (Order $o) => [
                'id' => $o->publicId(), 'number' => $o->getAttribute('number'), 'status' => $o->status, 'is_paid' => $o->is_paid,
                'is_cod' => $o->is_cod, 'created_at' => $o->created_at?->toIso8601String(), 'paid_at' => $o->paid_at?->toIso8601String(),
                'total' => $o->total, 'refunded' => $o->refunded, 'currency' => $o->getAttribute('currency'),
                'payment_method' => $o->getAttribute('payment_method_title'), 'coupons' => $o->coupon_codes ?? [],
                'city' => $o->getAttribute('shipping_city'), 'channel_last' => $o->getAttribute('channel_last'),
                'order_index' => $o->getAttribute('order_index'),
                'items' => $o->items->map(fn ($i) => [
                    'name' => $i->getAttribute('name'), 'sku' => $i->getAttribute('sku'), 'qty' => (string) $i->getAttribute('qty'),
                    'line_total' => (string) $i->getAttribute('line_total'),
                ])->all(),
            ])->all(),
            'meta' => ['total' => $paginator->total(), 'page' => $paginator->currentPage(), 'per_page' => $paginator->perPage()],
        ];
    }

    /**
     * Afinidad por categoría, marca y producto: compras (MariaDB) y vistas (ClickHouse).
     *
     * @return array<string, mixed>
     */
    public function products(Workspace $workspace, Contact $contact): array
    {
        $ids = ExportContactData::contactIds($contact);
        $items = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $workspace->id)->whereIn('o.contact_id', $ids)->where('o.is_paid', true)
            ->get(['i.external_product_id', 'i.name', 'i.brand', 'i.category_ids', 'i.qty', 'i.line_total']);
        $categories = [];
        $brands = [];
        $products = [];
        foreach ($items as $item) {
            foreach ((array) json_decode((string) $item->category_ids, true) as $cat) {
                $categories[(string) $cat]['purchases'] = ($categories[(string) $cat]['purchases'] ?? 0) + 1;
                $categories[(string) $cat]['revenue'] = ($categories[(string) $cat]['revenue'] ?? 0) + (float) $item->line_total;
            }
            if ($item->brand) {
                $brands[(string) $item->brand]['purchases'] = ($brands[(string) $item->brand]['purchases'] ?? 0) + 1;
                $brands[(string) $item->brand]['revenue'] = ($brands[(string) $item->brand]['revenue'] ?? 0) + (float) $item->line_total;
            }
            $key = (string) $item->external_product_id;
            $products[$key] = ['name' => $item->name, 'purchases' => ($products[$key]['purchases'] ?? 0) + (float) $item->qty, 'views' => 0];
        }
        $anonymous = array_values(ContactIdentity::query()->whereIn('contact_id', $ids)->where('type', 'anonymous_id')->pluck('value')->map(fn ($v) => (string) $v)->all());
        $views = Ch::forWorkspace($workspace)->select(
            "SELECT product_id, any(JSONExtractString(toJSONString(properties), 'name')) AS name, count() AS views
             FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'product.viewed'
               AND (has({ids:Array(String)}, contact_id) OR has({anon:Array(String)}, anonymous_id))
             GROUP BY product_id ORDER BY views DESC LIMIT 20",
            ['ids' => ExportContactData::chArray($ids), 'anon' => ExportContactData::chArray($anonymous)],
        );
        foreach ($views as $v) {
            $key = (string) $v['product_id'];
            $products[$key] = ['name' => $products[$key]['name'] ?? $v['name'], 'purchases' => $products[$key]['purchases'] ?? 0, 'views' => (int) $v['views']];
        }
        $names = DB::table('product_categories')->where('workspace_id', $workspace->id)->whereIn('external_id', array_keys($categories))->pluck('name', 'external_id');
        $sort = fn (array $rows) => collect($rows)->sortByDesc(fn ($r) => $r['purchases'] ?? 0)->take(10);

        return [
            'categories' => $sort($categories)->map(fn ($v, $k) => ['id' => (string) $k, 'name' => $names[$k] ?? (string) $k] + $v)->values(),
            'brands' => $sort($brands)->map(fn ($v, $k) => ['name' => (string) $k] + $v)->values(),
            'products' => collect($products)->sortByDesc(fn ($r) => ($r['purchases'] * 5) + $r['views'])->take(15)->map(fn ($v, $k) => ['id' => (string) $k] + $v)->values(),
            'affinity' => $contact->getAttribute('category_affinity'),
        ];
    }

    /** Si el contacto se fusionó en otro, el que quedó (sigue la cadena de fusiones). */
    public static function survivor(Contact $contact): ?Contact
    {
        $current = $contact;
        for ($hop = 0; $hop < 10 && $current->getAttribute('merged_into_id') !== null; $hop++) {
            $next = Contact::query()->whereKey($current->getAttribute('merged_into_id'))->first();
            if ($next === null) {
                break;
            }
            $current = $next;
        }

        return $current->is($contact) ? null : $current;
    }

    /** @return list<array<string, mixed>> */
    public function identities(Contact $contact): array
    {
        $sites = Site::query()->pluck('name', 'id');
        $labels = ['customer_ref' => __('Cuenta en la tienda'), 'email_hash' => __('Correo'), 'phone_hash' => __('Teléfono'), 'anonymous_id' => __('Navegador o dispositivo'), 'wa_id' => 'WhatsApp', 'link_token' => __('Enlace de un mensaje'), 'external' => __('ID externo')];

        return array_values(ContactIdentity::query()->whereIn('contact_id', ExportContactData::contactIds($contact))->orderBy('type')->get()->map(function (ContactIdentity $i) use ($sites, $labels, $contact): array {
            $display = match ($i->type) {
                'customer_ref' => explode(':', $i->value, 2)[1] ?? $i->value,
                'email_hash' => ContactAccess::email($contact->email) ?? '•••',
                'phone_hash' => ContactAccess::phone($contact->phone) ?? '•••',
                'anonymous_id' => mb_substr($i->value, 0, 8).'…',
                default => '•••',
            };

            return [
                'type' => $i->type, 'label' => $labels[$i->type] ?? $i->type, 'display' => $display,
                'site' => $i->getAttribute('site_id') ? ($sites[$i->getAttribute('site_id')] ?? null) : null,
                'first_seen_at' => $i->getAttribute('first_seen_at')?->toIso8601String(),
                'last_seen_at' => $i->last_seen_at?->toIso8601String(),
            ];
        })->all());
    }
}
