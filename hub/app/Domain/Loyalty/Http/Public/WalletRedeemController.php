<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Http\Public;

use App\Domain\Commerce\Exceptions\CouponIssueException;
use App\Domain\Loyalty\Actions\RedeemWallet;
use App\Domain\Sites\Models\Site;
use App\Support\Money\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * `POST /v1/s/wallet/redeem` (api-connector.md §5.5): el botón «Usar saldo» del
 * checkout. Firmado por el sitio (VerifySiteSignature). Única llamada síncrona
 * del plugin al hub: el plugin espera como mucho 3 s.
 */
final class WalletRedeemController
{
    public function __invoke(Request $request, RedeemWallet $redeem): JsonResponse
    {
        /** @var Site $site */
        $site = $request->attributes->get('igr_site');
        $key = 'igr-wallet:'.$site->id;
        if (RateLimiter::tooManyAttempts($key, 120)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'rate_limited']], 429);
        }
        RateLimiter::hit($key, 60);

        $body = json_decode($request->getContent(), true);
        $ref = is_array($body) && is_string($body['customer_ref'] ?? null) ? trim($body['customer_ref']) : '';
        $total = is_array($body) && is_numeric($body['cart_total'] ?? null) ? Decimal::of((string) $body['cart_total']) : null;
        $currency = is_array($body) && is_string($body['currency'] ?? null) && preg_match('/^[A-Za-z]{3}$/', $body['currency']) === 1 ? $body['currency'] : null;
        $cart = is_array($body) && is_string($body['cart_token'] ?? null) && $body['cart_token'] !== '' ? mb_substr($body['cart_token'], 0, 64) : null;
        if ($ref === '' || strlen($ref) > 80 || $total === null || (float) $total <= 0 || $currency === null) {
            return response()->json(['ok' => false, 'error' => ['code' => 'invalid_args']], 422);
        }

        try {
            $result = $redeem->handle($site, $ref, $total, $currency, $cart);
        } catch (CouponIssueException $e) {
            Log::warning('igr.loyalty.wallet_coupon_failed', ['site' => $site->id]);

            return response()->json(['ok' => false, 'error' => ['code' => 'coupon_failed']], 502);
        } catch (RuntimeException $e) {
            $code = $e->getMessage() === 'busy' ? 'busy' : ($e->getMessage() === 'currency' ? 'currency_mismatch' : 'no_balance');

            return response()->json(['ok' => false, 'error' => ['code' => $code]], 409);
        }
        if ($result === null) {
            return response()->json(['ok' => false, 'error' => ['code' => 'no_balance']], 409);
        }

        return response()->json([
            'coupon' => [
                'code' => $result['coupon']->code,
                'amount' => Decimal::of($result['amount']),
                'expires_at' => $result['expires_at']->toIso8601String(),
            ],
            'balance' => Decimal::of($result['balance']),
        ]);
    }
}
