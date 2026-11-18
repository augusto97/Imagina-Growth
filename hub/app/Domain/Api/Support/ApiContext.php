<?php

declare(strict_types=1);

namespace App\Domain\Api\Support;

use App\Domain\Api\Models\ApiClient;
use Illuminate\Http\Request;
use RuntimeException;

/** El cliente de la API que hace la petición (lo pone AuthenticateApiClient). */
final class ApiContext
{
    public const ATTRIBUTE = 'igr_api_client';

    public static function client(Request $request): ApiClient
    {
        $client = $request->attributes->get(self::ATTRIBUTE);
        if (! $client instanceof ApiClient) {
            throw new RuntimeException('Petición de la API sin cliente autenticado.');
        }

        return $client;
    }

    public static function can(Request $request, string $scope): bool
    {
        return self::client($request)->can($scope);
    }
}
