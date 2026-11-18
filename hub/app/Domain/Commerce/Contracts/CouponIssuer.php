<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Contracts;

use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Models\CouponIssued;

/**
 * Crea un cupón único en el sitio con el comando firmado `coupon.create`
 * (api-connector.md §6) y lo registra en `coupons_issued`. Lanza
 * App\Domain\Commerce\Exceptions\CouponIssueException (mensaje en español) si
 * el sitio no responde o lo rechaza; el registro queda `failed` con el error.
 * Debe correr dentro del contexto del espacio de trabajo.
 */
interface CouponIssuer
{
    public function issue(CouponSpec $spec): CouponIssued;
}
