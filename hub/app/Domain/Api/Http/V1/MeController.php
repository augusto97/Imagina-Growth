<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\V1;

use Dedoc\Scramble\Attributes\Group;
use App\Domain\Api\Support\ApiContext;
use App\Domain\Api\Support\ClientResolver;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 */
#[Group('Cuenta', weight: 0)]
final class MeController
{
    /**
     * Quién soy
     *
     * Devuelve el espacio de trabajo del token, sus alcances y el límite de peticiones por minuto.
     * Sirve para probar que el token funciona.
     */
    public function __invoke(Request $request, CurrentWorkspace $current, ClientResolver $resolver): JsonResponse
    {
        $client = ApiContext::client($request);
        $workspace = $current->get();

        return new JsonResponse([
            'workspace' => [
                'id' => $workspace->publicId(), 'name' => $workspace->name, 'currency' => $workspace->currency,
                'timezone' => $workspace->timezone, 'country' => $workspace->country,
            ],
            'client' => ['id' => $client->publicId(), 'name' => $client->name, 'kind' => $client->kind, 'scopes' => array_values((array) $client->scopes)],
            'rate_limit' => ['per_minute' => $resolver->perMinute($workspace)],
        ]);
    }
}
