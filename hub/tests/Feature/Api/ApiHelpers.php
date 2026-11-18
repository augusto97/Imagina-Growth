<?php

use App\Domain\Api\Actions\IssueApiToken;
use App\Domain\Api\Models\ApiClient;
use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;

/*
 * Ayudas de las pruebas de la API pública, los webhooks y el servidor MCP (M19).
 * Prefijo `api` para no chocar con las de otros módulos.
 */

if (! function_exists('apiEntitlements')) {
    /**
     * Plan de prueba: todo permitido salvo los módulos en $deny, con el límite por minuto dado.
     *
     * @param  list<string>  $deny
     */
    function apiEntitlements(array $deny = [], ?int $perMinute = null): void
    {
        app()->instance(Entitlements::class, new class($deny, $perMinute) implements Entitlements
        {
            /** @param list<string> $deny */
            public function __construct(private array $deny, private ?int $perMinute) {}

            public function allows(Workspace $workspace, string $module): bool
            {
                return ! in_array($module, $this->deny, true);
            }

            public function limit(Workspace $workspace, string $key): ?int
            {
                return $key === 'api_requests_per_minute' ? $this->perMinute : null;
            }

            public function planFor(Workspace $workspace): ?string
            {
                return 'prueba';
            }
        });
    }

    /**
     * Crea un token del espacio de trabajo y devuelve [token en claro, cliente].
     *
     * @param  list<string>  $scopes
     * @return array{0: string, 1: ApiClient}
     */
    function apiToken(Workspace $ws, array $scopes, string $kind = 'token', string $name = 'Zapier'): array
    {
        [$client, $plain] = app(CurrentWorkspace::class)->run($ws, fn () => app(IssueApiToken::class)->handle($ws->id, $name, $kind, $scopes, null));

        return [$plain, $client];
    }

    /** @return array<string, string> */
    function apiHeaders(string $token, array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'] + $extra;
    }
}
