<?php

declare(strict_types=1);

namespace App\Domain\Agency\Http;

use App\Domain\Agency\Models\AccountBranding as BrandingModel;
use App\Domain\Agency\Models\CustomDomain;
use App\Domain\Agency\Support\AgencyBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/public/branding — la marca de la pantalla de ingreso según el
 * dominio: en el dominio propio verificado de una agencia, la suya; si no, la de
 * la plataforma. Público y sin datos personales.
 */
final class PublicBrandingController
{
    public function __invoke(Request $request): JsonResponse
    {
        $host = strtolower($request->getHost());
        $accountId = CustomDomain::query()->where('host', $host)->where('purpose', 'panel')->where('status', 'verified')->value('account_id');
        $b = is_string($accountId) ? BrandingModel::query()->find($accountId) : null;
        if ($b === null) {
            return response()->json(['branding' => ['name' => 'Imagina Growth', 'logo_url' => null, 'primary_color' => null, 'white_label' => false]])
                ->header('Cache-Control', 'public, max-age=300');
        }

        return response()->json(['branding' => [
            'name' => (string) ($b->brand_name ?: 'Imagina Growth'),
            'logo_url' => AgencyBranding::logoUrl($b),
            'primary_color' => $b->primary_color,
            'white_label' => $b->white_label,
        ]])->header('Cache-Control', 'public, max-age=300');
    }
}
