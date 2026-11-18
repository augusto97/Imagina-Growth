<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Referrals\Models\ReferralProgram;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Money\Decimal;

/**
 * Lectura del programa de referidos con sus valores por defecto, los textos de las
 * recompensas («10% en tu primera compra», «500 puntos») y los cupones que da.
 */
final class ProgramSettings
{
    public const DEFAULT_SHARE_TEXT = '¡Hola! Te regalo {regalo} en tu primera compra en {tienda}. Entra aquí: {enlace}';

    /** @var array<string, mixed> */
    public readonly array $friend;

    /** @var array<string, mixed> */
    public readonly array $referrer;

    /** @var array<string, mixed> */
    public readonly array $rules;

    public function __construct(public readonly ReferralProgram $program, private readonly Workspace $workspace)
    {
        $this->friend = $program->friend_reward + ['kind' => 'percent', 'amount' => '10.00', 'expires_days' => 30, 'minimum_amount' => null];
        $this->referrer = $program->referrer_reward + ['type' => 'points', 'points' => 500, 'amount' => null, 'coupon_kind' => 'percent', 'expires_days' => 60];
        $this->rules = ($program->rules ?? []) + self::defaultRules();
    }

    /** @return array<string, mixed> */
    public static function defaultRules(): array
    {
        return [
            'min_order' => null,           // compra mínima del amigo para calificar (moneda del espacio)
            'monthly_limit' => 10,         // recompensas por persona al mes (null = sin límite)
            'return_days' => 15,           // periodo de devolución después de completado
            'qualify_on' => 'completed',   // completed | paid
            'review_suspicious' => true,   // las señales de fraude van a revisión manual
            'signal_window_days' => 30,    // ventana para comparar IP y navegador
        ];
    }

    public function returnDays(): int
    {
        return max(0, min(90, (int) $this->rules['return_days']));
    }

    public function minOrder(): ?string
    {
        $min = $this->rules['min_order'] ?? null;

        return is_numeric($min) && (float) $min > 0 ? Decimal::of((string) $min) : null;
    }

    public function monthlyLimit(): ?int
    {
        $limit = $this->rules['monthly_limit'] ?? null;

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }

    public function qualifiesOnPaid(): bool
    {
        return ($this->rules['qualify_on'] ?? 'completed') === 'paid';
    }

    public function reviewSuspicious(): bool
    {
        return (bool) ($this->rules['review_suspicious'] ?? true);
    }

    public function signalWindowDays(): int
    {
        return max(1, min(180, (int) ($this->rules['signal_window_days'] ?? 30)));
    }

    public function friendCoupon(string $friendContactId, string $referralId, ?string $siteId): CouponSpec
    {
        $kind = (string) $this->friend['kind'];
        $minimum = $this->friend['minimum_amount'] ?? null;

        return new CouponSpec(
            kind: $kind,
            amount: $kind === 'free_shipping' ? '0.00' : Decimal::of((string) $this->friend['amount']),
            sourceType: 'referral',
            sourceId: $referralId,
            contactId: $friendContactId,
            expiresInDays: max(1, (int) $this->friend['expires_days']),
            prefix: 'AMIGO',
            minimumAmount: is_numeric($minimum) && (float) $minimum > 0 ? Decimal::of((string) $minimum) : $this->minOrder(),
            individualUse: true,
            restrictToContact: true,
            siteId: $siteId,
            description: __('Regalo de bienvenida por referido'),
        );
    }

    public function referrerCoupon(string $referrerContactId, string $referralId): CouponSpec
    {
        $kind = (string) ($this->referrer['coupon_kind'] ?? 'percent');

        return new CouponSpec(
            kind: $kind,
            amount: $kind === 'free_shipping' ? '0.00' : Decimal::of((string) ($this->referrer['amount'] ?? '0')),
            sourceType: 'referral',
            sourceId: $referralId,
            contactId: $referrerContactId,
            expiresInDays: max(1, (int) ($this->referrer['expires_days'] ?? 60)),
            prefix: 'GRACIAS',
            description: __('Gracias por recomendarnos'),
        );
    }

    /** «10% en tu primera compra», «$20.000 en tu primera compra», «Envío gratis en tu primera compra». */
    public function friendRewardLabel(): string
    {
        return __(':reward en tu primera compra', ['reward' => $this->discountLabel((string) $this->friend['kind'], (string) $this->friend['amount'])]);
    }

    /** «500 puntos», «$20.000 de saldo a favor», «Un cupón de 10%». */
    public function referrerRewardLabel(): string
    {
        return match ((string) $this->referrer['type']) {
            'wallet' => __(':amount de saldo a favor', ['amount' => MoneyLabel::format((string) ($this->referrer['amount'] ?? '0'), $this->workspace->currency, $this->workspace->locale)]),
            'coupon' => __('Un cupón de :reward', ['reward' => $this->discountLabel((string) ($this->referrer['coupon_kind'] ?? 'percent'), (string) ($this->referrer['amount'] ?? '0'))]),
            default => __(':points puntos', ['points' => number_format((int) ($this->referrer['points'] ?? 0), 0, ',', '.')]),
        };
    }

    /** Valor en la moneda del espacio de lo que se le da a quien refiere (para el costo por referido). Los puntos no tienen precio fijo: null. */
    public function referrerRewardValue(): ?string
    {
        return match ((string) $this->referrer['type']) {
            'wallet' => Decimal::of((string) ($this->referrer['amount'] ?? '0')),
            'coupon' => ($this->referrer['coupon_kind'] ?? 'percent') === 'fixed_cart' ? Decimal::of((string) ($this->referrer['amount'] ?? '0')) : null,
            default => null,
        };
    }

    /** Texto para compartir con las variables ya reemplazadas. */
    public function shareText(string $link, string $code, string $storeName): string
    {
        $default = __(self::DEFAULT_SHARE_TEXT);
        $text = trim((string) ($this->program->share_text ?? '')) !== '' ? (string) $this->program->share_text : (is_string($default) ? $default : self::DEFAULT_SHARE_TEXT);

        return strtr($text, ['{regalo}' => $this->discountLabel((string) $this->friend['kind'], (string) $this->friend['amount']), '{tienda}' => $storeName, '{enlace}' => $link, '{codigo}' => $code]);
    }

    private function discountLabel(string $kind, string $amount): string
    {
        return match ($kind) {
            'free_shipping' => __('Envío gratis'),
            'percent' => rtrim(rtrim(Decimal::of($amount), '0'), '.').'%',
            default => MoneyLabel::format($amount, $this->workspace->currency, $this->workspace->locale),
        };
    }
}
