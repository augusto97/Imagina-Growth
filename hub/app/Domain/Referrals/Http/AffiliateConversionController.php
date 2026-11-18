<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Http;

use App\Domain\Commerce\Models\Order;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Models\AffiliateConversion;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Ids\PrefixedId;
use App\Support\Money\Decimal;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comisiones de afiliados (modulos.md M14): lista, pagos por hacer (aprobadas por
 * afiliado), exportación para pagar a mano y «marcar pagadas». Ver la lista:
 * viewDashboards; pagos y exportación (llevan datos de pago): viewPaymentData.
 */
final class AffiliateConversionController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET affiliate-conversions?status=&affiliate=&page= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $request->validate(['status' => ['nullable', Rule::in(AffiliateConversion::STATUSES)], 'affiliate' => ['nullable', 'string', 'max:40'], 'page' => ['nullable', 'integer', 'min:1']]);
        $affiliateId = PrefixedId::decode(Affiliate::ID_PREFIX, $request->string('affiliate')->toString());
        $page = AffiliateConversion::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($affiliateId !== null, fn ($q) => $q->where('affiliate_id', $affiliateId))
            ->orderByDesc('created_at')->paginate(50);
        /** @var list<AffiliateConversion> $items */
        $items = $page->items();
        $affiliates = Affiliate::query()->whereIn('id', array_map(fn ($c) => $c->affiliate_id, $items))->get(['id', 'name'])->keyBy('id');
        $orders = Order::query()->whereIn('id', array_map(fn ($c) => $c->order_id, $items))->get(['id', 'number', 'external_id', 'utm_last'])->keyBy('id');

        return response()->json([
            'data' => array_map(fn (AffiliateConversion $c) => self::present($c, $affiliates->get($c->affiliate_id), $orders->get($c->order_id)), $items),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** GET affiliate-conversions/payouts: comisiones aprobadas por pagar, por afiliado. */
    public function payouts(): JsonResponse
    {
        Gate::authorize('ws.viewPaymentData');

        return response()->json(['data' => $this->pending()]);
    }

    /** GET affiliate-conversions/payouts.csv */
    public function export(RecordAuditLog $audit): StreamedResponse
    {
        Gate::authorize('ws.viewPaymentData');
        $rows = $this->pending();
        $audit->handle('affiliates.payouts_exported', null, ['affiliates' => count($rows), 'conversions' => array_sum(array_map(fn ($r) => count($r['conversion_ids']), $rows))], $this->current->id());
        $currency = $this->current->get()->currency;
        $filename = 'comisiones-por-pagar-'.CarbonImmutable::now($this->current->get()->timezone ?: 'America/Bogota')->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $currency): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel abre bien las tildes
            fputcsv($out, [__('Afiliado'), __('Tipo'), __('Correo'), __('Datos de pago'), __('Pedidos'), __('Comisión'), __('Moneda'), __('Comisiones')], ';');
            foreach ($rows as $row) {
                fputcsv($out, [self::cell($row['name']), $row['kind'], self::cell((string) $row['email']), self::cell((string) $row['payout_info']), $row['orders'], $row['commission'], $currency, implode(' ', $row['conversion_ids'])], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /** POST affiliate-conversions/mark-paid {ids: [...]} (solo las aprobadas) */
    public function markPaid(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.viewPaymentData');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:2000'], 'ids.*' => ['string', 'max:40']]);
        $ids = array_values(array_filter(array_map(fn ($id) => PrefixedId::decode(AffiliateConversion::ID_PREFIX, (string) $id), $data['ids'])));
        $updated = AffiliateConversion::query()->whereIn('id', $ids)->where('status', 'approved')->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
        $audit->handle('affiliates.marked_paid', null, ['count' => $updated], $this->current->id());

        return response()->json(['updated' => $updated]);
    }

    /** @return list<array{affiliate_id: string, name: string, kind: string, email: string|null, payout_info: string|null, orders: int, commission: string, conversion_ids: list<string>}> */
    private function pending(): array
    {
        $groups = AffiliateConversion::query()->where('status', 'approved')->orderBy('created_at')->limit(5000)->get()->groupBy('affiliate_id');
        $affiliates = Affiliate::query()->whereIn('id', $groups->keys()->all())->get()->keyBy('id');
        $rows = [];
        foreach ($groups as $affiliateId => $conversions) {
            $affiliate = $affiliates->get($affiliateId);
            if ($affiliate === null) {
                continue;
            }
            $rows[] = [
                'affiliate_id' => $affiliate->publicId(),
                'name' => $affiliate->name,
                'kind' => $affiliate->kind,
                'email' => $affiliate->email,
                'payout_info' => $affiliate->payout_info,
                'orders' => $conversions->count(),
                'commission' => Decimal::add('0', ...$conversions->pluck('commission_ws')->map(fn ($v) => (string) $v)->all()),
                'conversion_ids' => array_values($conversions->map(fn (AffiliateConversion $c) => $c->publicId())->all()),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /** Evita la inyección de fórmulas al abrir el CSV en una hoja de cálculo. */
    private static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** @return array<string, mixed> */
    public static function present(AffiliateConversion $c, ?Affiliate $affiliate, ?Order $order): array
    {
        $utm = $order?->getAttribute('utm_last');

        return [
            'id' => $c->publicId(),
            'affiliate' => $affiliate !== null ? ['id' => $affiliate->publicId(), 'name' => $affiliate->name] : null,
            'order' => $order !== null ? ['id' => $order->publicId(), 'number' => (string) ($order->getAttribute('number') ?: $order->external_id)] : null,
            'campaign' => is_array($utm) && is_string($utm['campaign'] ?? null) ? $utm['campaign'] : null,
            'via' => $c->via,
            'amount_ws' => Decimal::of((string) $c->amount_ws),
            'commission_ws' => Decimal::of((string) $c->commission_ws),
            'status' => $c->status,
            'void_reason' => $c->void_reason,
            'qualifies_at' => $c->qualifies_at?->toIso8601String(),
            'approved_at' => $c->approved_at?->toIso8601String(),
            'paid_at' => $c->paid_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
