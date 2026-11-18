<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Exceptions\CouponIssueException;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sites\Actions\SendSiteCommand;
use App\Domain\Sites\Models\Site;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cupón único en el sitio por el comando `coupon.create` (api-connector.md §6).
 * El código es aleatorio (sin 0/O ni 1/I) y no secuencial; se reintenta con el
 * mismo código, que el plugin trata de forma idempotente.
 */
final class SiteCouponIssuer implements CouponIssuer
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly SendSiteCommand $command) {}

    public function issue(CouponSpec $spec): CouponIssued
    {
        $site = $spec->siteId !== null
            ? Site::query()->find($spec->siteId)
            : Site::query()->where('status', 'active')->orderBy('created_at')->first();
        if ($site === null) {
            throw new CouponIssueException(__('No hay un sitio conectado para crear el cupón.'));
        }
        $coupon = CouponIssued::query()->create([
            'workspace_id' => $site->workspace_id,
            'site_id' => $site->id,
            'code' => self::code($spec->prefix),
            'contact_id' => $spec->contactId,
            'source_type' => $spec->sourceType,
            'source_id' => $spec->sourceId,
            'kind' => $spec->kind,
            'amount' => $spec->amount,
            'rules' => array_filter([
                'minimum_amount' => $spec->minimumAmount,
                'product_ids' => $spec->productIds,
                'category_ids' => $spec->categoryIds,
                'individual_use' => $spec->individualUse,
            ], fn ($v) => $v !== null && $v !== []),
            'expires_at' => now()->addDays(max(1, $spec->expiresInDays))->endOfDay(),
            'status' => 'pending',
        ]);

        $email = $spec->restrictToContact && $spec->contactId !== null ? Contact::query()->find($spec->contactId)?->email : null;
        $args = array_filter([
            'code' => $coupon->code,
            'kind' => $spec->kind,
            'amount' => $spec->amount,
            'expires_at' => $coupon->expires_at?->setTimezone($site->timezone ?: 'America/Bogota')->toDateString(),
            'usage_limit' => 1,
            'email_restrictions' => $email !== null ? [$email] : null,
            'individual_use' => $spec->individualUse,
            'minimum_amount' => $spec->minimumAmount,
            'product_ids' => $spec->productIds,
            'category_ids' => $spec->categoryIds,
            'free_shipping' => $spec->kind === 'free_shipping',
            'description' => mb_substr($spec->description ?? __('Cupón de Imagina Growth'), 0, 200),
        ], fn ($v) => $v !== null && $v !== []);

        try {
            $data = $this->command->handle($site, 'coupon.create', $args, timeout: 10);
        } catch (RuntimeException $e) {
            $coupon->forceFill(['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            Log::warning('igr.coupon.issue_failed', ['coupon' => $coupon->id, 'site' => $site->id]);

            throw new CouponIssueException(__('El sitio no pudo crear el cupón: :msg', ['msg' => $e->getMessage()]), 0, $e);
        }
        $coupon->forceFill(['status' => 'active', 'external_coupon_id' => isset($data['coupon_id']) ? (string) $data['coupon_id'] : null])->save();

        return $coupon;
    }

    public static function code(string $prefix): string
    {
        $suffix = '';
        for ($i = 0; $i < 8; $i++) {
            $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'IGR');

        return mb_substr($prefix, 0, 12).'-'.$suffix;
    }
}
