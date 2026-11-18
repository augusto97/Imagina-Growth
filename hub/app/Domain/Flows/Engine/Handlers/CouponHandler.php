<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Exceptions\CouponIssueException;
use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use Illuminate\Support\Str;

/**
 * Genera un cupón único en el sitio (CouponIssuer) y lo deja en
 * `context.cupon` para las plantillas: {codigo, descuento, vence}. En una prueba
 * usa un código de ejemplo; el grupo de control no recibe cupón.
 */
final class CouponHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        if ($state->isHoldout()) {
            return Outcome::next(record: 'skipped_holdout');
        }
        $c = $node['config'];
        $kind = (string) ($c['kind'] ?? 'percent');
        $amount = number_format((float) ($c['amount'] ?? 0), 2, '.', '');
        $days = max(1, min(90, (int) ($c['expires_in_days'] ?? 7)));
        $discount = match ($kind) {
            'percent' => rtrim(rtrim($amount, '0'), '.').'%',
            'free_shipping' => __('envío gratis'),
            default => $amount,
        };
        $expires = $state->now->addDays($days)->setTimezone($state->workspace->timezone ?: 'America/Bogota')->toDateString();

        if ($state->isTest()) {
            $state->remember('cupon', ['codigo' => 'PRUEBA-'.strtoupper(Str::random(6)), 'descuento' => $discount, 'vence' => $expires]);

            return Outcome::next(details: ['test' => true]);
        }
        try {
            $coupon = app(CouponIssuer::class)->issue(new CouponSpec(
                kind: $kind,
                amount: $amount,
                sourceType: 'flow',
                sourceId: $state->flow->id,
                contactId: $state->run->contact_id,
                expiresInDays: $days,
                prefix: (string) ($c['prefix'] ?? 'IGR'),
                minimumAmount: isset($c['minimum_amount']) && $c['minimum_amount'] !== '' ? number_format((float) $c['minimum_amount'], 2, '.', '') : null,
                description: __('Flujo «:name»', ['name' => mb_substr($state->flow->name, 0, 120)]),
            ));
        } catch (CouponIssueException $e) {
            return Outcome::next('failed', 'failed', ['reason' => 'coupon_failed', 'error' => mb_substr($e->getMessage(), 0, 300)], fallback: true);
        }
        $state->remember('cupon', ['codigo' => $coupon->code, 'descuento' => $discount, 'vence' => $expires]);

        return Outcome::next(details: ['coupon_id' => $coupon->publicId(), 'code' => $coupon->code]);
    }
}
