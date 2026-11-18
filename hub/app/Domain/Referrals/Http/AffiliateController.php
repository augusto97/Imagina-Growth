<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Http;

use App\Domain\Commerce\Models\Order;
use App\Domain\Referrals\Actions\SendCreatorPortalLink;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Models\AffiliateConversion;
use App\Domain\Referrals\Queries\CreatorPortalData;
use App\Domain\Referrals\Support\ReferralCodes;
use App\Domain\Referrals\Support\ReferralSwitch;
use App\Domain\Sites\Actions\SendSiteCommand;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Money\Decimal;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Afiliados e influencers (modulos.md M14). Ver: viewDashboards; crear, editar,
 * archivar y enviar el enlace del panel: manageMarketing. El correo lo ve y cambia
 * quien ve los contactos (viewAllContacts); los datos de pago, solo viewPaymentData.
 */
final class AffiliateController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly ReferralCodes $codes) {}

    /** GET affiliates */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $affiliates = Affiliate::query()->where('status', '!=', 'archived')->orderBy('name')->limit(1000)->get();
        $stats = AffiliateConversion::query()->whereIn('affiliate_id', $affiliates->pluck('id')->all())->where('status', '!=', 'void')
            ->groupBy('affiliate_id')
            ->selectRaw("affiliate_id, COUNT(*) AS orders, SUM(amount_ws) AS revenue, SUM(commission_ws) AS commission,
                SUM(CASE WHEN status = 'pending' THEN commission_ws ELSE 0 END) AS pending, SUM(CASE WHEN status = 'approved' THEN commission_ws ELSE 0 END) AS approved")
            ->get()->keyBy('affiliate_id');

        return response()->json(['data' => $affiliates->map(fn (Affiliate $a) => $this->present($a) + ['stats' => self::stats($stats->get($a->id))])->values()]);
    }

    /** GET affiliates/{affiliate} */
    public function show(string $affiliate): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = $this->find($affiliate);
        $conversions = AffiliateConversion::query()->where('affiliate_id', $model->id)->orderByDesc('created_at')->limit(100)->get();
        $orders = Order::query()->whereIn('id', $conversions->pluck('order_id')->all())->get(['id', 'number', 'external_id', 'utm_last'])->keyBy('id');
        $stats = AffiliateConversion::query()->where('affiliate_id', $model->id)->where('status', '!=', 'void')
            ->selectRaw("COUNT(*) AS orders, SUM(amount_ws) AS revenue, SUM(commission_ws) AS commission,
                SUM(CASE WHEN status = 'pending' THEN commission_ws ELSE 0 END) AS pending, SUM(CASE WHEN status = 'approved' THEN commission_ws ELSE 0 END) AS approved")
            ->first();

        return response()->json($this->present($model) + [
            'stats' => self::stats($stats),
            'links' => Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->get()
                ->map(fn (Site $s) => ['site' => $s->name ?: $s->host, 'url' => ReferralCodes::link($s, $model->link_code)])->values(),
            'conversions' => $conversions->map(fn (AffiliateConversion $c) => AffiliateConversionController::present($c, null, $orders->get($c->order_id)))->values(),
        ]);
    }

    /** POST affiliates */
    public function store(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $this->validated($request, null);
        $affiliate = new Affiliate(['status' => 'active']);
        $this->apply($affiliate, $data, $request);
        $affiliate->save();
        ReferralSwitch::forget($this->current->id());
        $audit->handle('affiliates.created', $affiliate, ['name' => $affiliate->name, 'kind' => $affiliate->kind, 'link_code' => $affiliate->link_code], $this->current->id());

        return response()->json($this->present($affiliate) + ['stats' => self::stats(null)], 201);
    }

    /** PATCH affiliates/{affiliate} */
    public function update(Request $request, string $affiliate, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($affiliate);
        $data = $this->validated($request, $model);
        $this->apply($model, $data, $request);
        $model->save();
        ReferralSwitch::forget($this->current->id());
        $audit->handle('affiliates.updated', $model, array_intersect_key($data, array_flip(['name', 'kind', 'status', 'commission', 'coupon_codes', 'link_code'])), $this->current->id());

        return response()->json($this->present($model));
    }

    /** DELETE affiliates/{affiliate}: se archiva (sus comisiones y pedidos quedan). */
    public function destroy(string $affiliate, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($affiliate);
        $model->forceFill(['status' => 'archived', 'portal_nonce' => null])->save();
        ReferralSwitch::forget($this->current->id());
        $audit->handle('affiliates.archived', $model, [], $this->current->id());

        return response()->json(['ok' => true]);
    }

    /** POST affiliates/{affiliate}/portal-link {email: bool} */
    public function portalLink(Request $request, string $affiliate, SendCreatorPortalLink $send, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($affiliate);
        abort_if($model->status === 'archived', 409, __('Este afiliado está archivado.'));
        $result = $send->handle($model, $request->boolean('email', true));
        $audit->handle('affiliates.portal_link', $model, ['sent' => $result['sent']], $this->current->id());

        return response()->json($result + ['portal_sent_at' => $model->portal_sent_at?->toIso8601String()]);
    }

    /** POST affiliates/{affiliate}/store-coupon {code, kind, amount}: crea el cupón en la tienda (varios usos, uno por cliente). */
    public function storeCoupon(Request $request, string $affiliate, SendSiteCommand $command, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($affiliate);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{3,40}$/'],
            'kind' => ['required', Rule::in(['percent', 'fixed_cart', 'free_shipping'])],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000000', Rule::requiredIf(fn () => $request->input('kind') !== 'free_shipping')],
        ]);
        $code = strtoupper($data['code']);
        $this->assertCouponsFree([$code], $model->id);
        $site = Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->first();
        if ($site === null) {
            throw ValidationException::withMessages(['code' => __('No hay un sitio conectado para crear el cupón.')]);
        }
        try {
            $command->handle($site, 'coupon.create', [
                'code' => $code,
                'kind' => $data['kind'],
                'amount' => $data['kind'] === 'free_shipping' ? '0.00' : Decimal::of((string) $data['amount']),
                'usage_limit' => 1000,
                'individual_use' => true,
                'free_shipping' => $data['kind'] === 'free_shipping',
                'description' => mb_substr(__('Cupón de :name (afiliado)', ['name' => $model->name]), 0, 200),
            ], timeout: 10);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['code' => __('El sitio no pudo crear el cupón: :msg', ['msg' => $e->getMessage()])]);
        }
        $model->coupon_codes = array_values(array_unique(array_merge($model->coupon_codes ?? [], [$code])));
        $model->save();
        $audit->handle('affiliates.store_coupon', $model, ['code' => $code], $this->current->id());

        return response()->json($this->present($model));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Affiliate $current): array
    {
        $partial = $current !== null;
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'min:2', 'max:160'],
            'kind' => [$partial ? 'sometimes' : 'required', Rule::in(Affiliate::KINDS)],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'coupon_codes' => ['sometimes', 'array', 'max:20'],
            'coupon_codes.*' => ['string', 'regex:/^[A-Za-z0-9_-]{3,40}$/'],
            'link_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{3,32}$/'],
            'commission' => [$partial ? 'sometimes' : 'required', 'array'],
            'commission.type' => ['required_with:commission', Rule::in(['percent', 'fixed'])],
            'commission.value' => ['required_with:commission', 'numeric', 'min:0', 'max:100000000'],
            'commission.basis' => ['nullable', Rule::in(['net', 'total'])],
            'material' => ['sometimes', 'array', 'max:20'],
            'material.*.label' => ['required', 'string', 'max:120'],
            'material.*.url' => ['required', 'url:https,http', 'max:500'],
            'payout_info' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
        ]);
        if (! Gate::allows('ws.viewAllContacts')) {
            unset($data['email']); // el correo es un dato personal
        }
        if (! Gate::allows('ws.viewPaymentData')) {
            unset($data['payout_info']); // los datos de pago solo los ven owner y admin
        }
        if (isset($data['commission']) && $data['commission']['type'] === 'percent' && (float) $data['commission']['value'] > 100) {
            throw ValidationException::withMessages(['commission.value' => __('El porcentaje no puede pasar de 100.')]);
        }
        if (isset($data['coupon_codes'])) {
            $data['coupon_codes'] = array_values(array_unique(array_map(fn ($c) => strtoupper((string) $c), $data['coupon_codes'])));
            $this->assertCouponsFree($data['coupon_codes'], $current?->id);
        }
        if (isset($data['link_code'])) {
            $code = strtoupper((string) $data['link_code']);
            $same = $current !== null && $current->link_code === $code;
            if (! $same && $this->codes->taken($code, $current?->id)) {
                throw ValidationException::withMessages(['link_code' => __('Ese código ya lo usa otro afiliado o un cliente. Elige otro.')]);
            }
            $data['link_code'] = $code;
        }

        return $data;
    }

    /** @param list<string> $codes */
    private function assertCouponsFree(array $codes, ?string $exceptId): void
    {
        $lower = array_map('mb_strtolower', $codes);
        foreach (Affiliate::query()->where('status', '!=', 'archived')->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->whereNotNull('coupon_codes')->get() as $other) {
            $clash = array_intersect($other->normalizedCoupons(), $lower);
            if ($clash !== []) {
                throw ValidationException::withMessages(['coupon_codes' => __('El cupón :code ya es de :name.', ['code' => strtoupper((string) reset($clash)), 'name' => $other->name])]);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function apply(Affiliate $affiliate, array $data, Request $request): void
    {
        foreach (['name', 'kind', 'coupon_codes', 'material', 'status'] as $key) {
            if (array_key_exists($key, $data)) {
                $affiliate->setAttribute($key, $data[$key]);
            }
        }
        if (isset($data['commission'])) {
            $affiliate->commission = [
                'type' => $data['commission']['type'],
                'value' => Decimal::of((string) $data['commission']['value']),
                'basis' => $data['commission']['basis'] ?? 'net',
            ];
        }
        if (array_key_exists('email', $data)) {
            $email = $data['email'] !== null ? mb_strtolower(trim((string) $data['email'])) : null;
            $affiliate->email = $email;
            $affiliate->email_bidx = $email !== null ? BlindIndex::email($this->current->id(), $email) : null;
        }
        if (array_key_exists('payout_info', $data)) {
            $affiliate->payout_info = $data['payout_info'] !== null && trim((string) $data['payout_info']) !== '' ? trim((string) $data['payout_info']) : null;
        }
        if (! $affiliate->exists || (array_key_exists('link_code', $data) && $data['link_code'] !== null)) {
            $affiliate->link_code = $data['link_code'] ?? $affiliate->getAttribute('link_code') ?? $this->codes->forAffiliate(null, (string) $affiliate->name);
        }
    }

    /** @return array<string, mixed> */
    private function present(Affiliate $a): array
    {
        $payment = Gate::allows('ws.viewPaymentData');
        $personal = Gate::allows('ws.viewAllContacts');

        return [
            'id' => $a->publicId(),
            'name' => $a->name,
            'kind' => $a->kind,
            'status' => $a->status,
            'email' => $personal ? $a->email : null,
            'has_email' => $a->email !== null && $a->email !== '',
            'payout_info' => $payment ? $a->payout_info : null,
            'coupon_codes' => array_values($a->coupon_codes ?? []),
            'link_code' => $a->link_code,
            'commission' => $a->commission,
            'commission_label' => CreatorPortalData::commissionLabel($a, $this->current->get()),
            'material' => array_values($a->material ?? []),
            'portal_sent_at' => $a->portal_sent_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /** @return array{orders: int, revenue: string, commission: string, pending: string, approved: string} */
    private static function stats(?object $row): array
    {
        $get = fn (string $k) => $row !== null && isset($row->{$k}) ? Decimal::of((string) $row->{$k}) : '0.00';

        return ['orders' => $row !== null ? (int) ($row->orders ?? 0) : 0, 'revenue' => $get('revenue'), 'commission' => $get('commission'), 'pending' => $get('pending'), 'approved' => $get('approved')];
    }

    private function find(string $publicId): Affiliate
    {
        return Affiliate::findByPublicId($publicId) ?? abort(404);
    }
}
