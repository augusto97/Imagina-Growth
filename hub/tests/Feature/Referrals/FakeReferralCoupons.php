<?php

declare(strict_types=1);

namespace Tests\Feature\Referrals;

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Exceptions\CouponIssueException;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Sites\Models\Site;
use Illuminate\Support\Str;

/** Cupones sin llamar al sitio: los registra activos y guarda lo que se pidió. */
final class FakeReferralCoupons implements CouponIssuer
{
    /** @var list<CouponSpec> */
    public array $issued = [];

    public bool $fail = false;

    public function issue(CouponSpec $spec): CouponIssued
    {
        if ($this->fail) {
            throw new CouponIssueException('El sitio no responde.');
        }
        $this->issued[] = $spec;
        $site = $spec->siteId !== null ? Site::query()->find($spec->siteId) : Site::query()->orderBy('created_at')->first();

        return CouponIssued::query()->create([
            'site_id' => $site?->id ?? 'SITE',
            'code' => $spec->prefix.'-'.strtoupper(Str::random(8)),
            'contact_id' => $spec->contactId,
            'source_type' => $spec->sourceType,
            'source_id' => $spec->sourceId,
            'kind' => $spec->kind,
            'amount' => $spec->amount,
            'rules' => array_filter(['minimum_amount' => $spec->minimumAmount]),
            'expires_at' => now()->addDays($spec->expiresInDays),
            'status' => 'active',
        ]);
    }
}
