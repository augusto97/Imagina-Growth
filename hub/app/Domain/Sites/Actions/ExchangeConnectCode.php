<?php

declare(strict_types=1);

namespace App\Domain\Sites\Actions;

use App\Domain\Sites\Exceptions\ConnectException;
use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Models\SiteConnectCode;
use App\Domain\Sites\Support\SiteKeys;
use App\Domain\Sites\Support\SiteSignatures;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Canje del código por las claves del sitio (api-connector.md §3.3).
 * Si el sitio ya existe en el espacio de trabajo (mismo host), se reconecta
 * con un secreto nuevo en lugar de crear otro.
 */
final class ExchangeConnectCode
{
    public function __construct(private RecordAuditLog $audit, private CurrentWorkspace $current) {}

    /**
     * @param  array<string, mixed>  $input  validado por el controlador
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $normalized = SiteKeys::normalizeCode((string) $input['code']);
        $code = $normalized === null ? null : SiteConnectCode::withoutWorkspaceScope()
            ->where('code_hash', SiteKeys::hashCode($normalized))
            ->first();

        if ($code === null || $code->used_at !== null || $code->expires_at->isPast()) {
            throw new ConnectException('invalid_code', __('El código no es válido o ya venció.'));
        }
        if ($code->nonce !== null && ! hash_equals($code->nonce, (string) ($input['nonce'] ?? ''))) {
            throw new ConnectException('nonce_mismatch', __('La conexión no coincide con la que se inició desde el sitio.'));
        }
        $host = SiteKeys::host((string) $input['site_url']);
        if ($host === null || ($code->site_host !== null && $code->site_host !== $host)) {
            throw new ConnectException('site_mismatch', __('La URL del sitio no coincide con la del código.'));
        }

        // Un solo uso, incluso con dos canjes simultáneos.
        $claimed = SiteConnectCode::withoutWorkspaceScope()->whereKey($code->id)->whereNull('used_at')->update(['used_at' => now()]);
        if ($claimed !== 1) {
            throw new ConnectException('invalid_code', __('El código no es válido o ya venció.'));
        }

        $workspace = Workspace::query()->findOrFail($code->workspace_id);

        return $this->current->run($workspace, function () use ($workspace, $code, $input, $host): array {
            $secret = SiteKeys::secret();

            $site = DB::transaction(function () use ($workspace, $code, $input, $host, $secret): Site {
                /** @var Site|null $site */
                $site = Site::query()->where('host', $host)->lockForUpdate()->first();
                $attributes = [
                    'platform' => $input['platform'] ?? $code->platform ?? 'woocommerce',
                    'name' => $input['name'] ?? $code->name ?? $host,
                    'url' => rtrim((string) $input['site_url'], '/'),
                    'host' => $host,
                    'rest_url' => $input['rest_url'] ?? null,
                    'domains' => array_values(array_unique([$host, 'www.'.$host])),
                    'secret' => $secret,
                    'secret_rotated_at' => now(),
                    'previous_secret' => null,
                    'previous_secret_expires_at' => null,
                    'status' => Site::STATUS_CONNECTED,
                    'timezone' => $input['timezone'] ?? $workspace->timezone,
                    'currency' => $input['currency'] ?? $workspace->currency,
                    'plugin_version' => $input['plugin_version'] ?? null,
                    'wp_version' => $input['wp_version'] ?? null,
                    'wc_version' => $input['wc_version'] ?? null,
                    'php_version' => $input['php_version'] ?? null,
                    'capabilities' => $input['capabilities'] ?? [],
                    'connected_at' => now(),
                    'last_seen_at' => now(),
                ];
                if ($site === null) {
                    $site = Site::query()->create($attributes + ['public_key' => SiteKeys::publicKey()]);
                } else {
                    $site->fill($attributes)->save();
                }
                $code->forceFill(['site_id' => $site->id])->save();

                return $site;
            });

            $this->audit->handle('site.connected', $site, ['host' => $host, 'plugin_version' => $site->getAttribute('plugin_version')], $workspace->id, $workspace->account_id, $code->getAttribute('created_by'));

            $ingest = rtrim((string) config('igr.urls.ingest'), '/');

            return [
                'site_id' => $site->publicId(),
                'public_key' => $site->public_key,
                'secret' => $secret,
                'workspace' => [
                    'id' => $workspace->publicId(),
                    'name' => $workspace->name,
                    'country' => $workspace->country,
                    'timezone' => $workspace->timezone,
                    'currency' => $workspace->currency,
                ],
                'endpoints' => [
                    'ingest_server' => $ingest.'/v1/s',
                    'ingest_browser' => $ingest.'/v1/b',
                    'api' => rtrim((string) config('igr.urls.app'), '/').'/',
                ],
                // Versiones de la firma que acepta y usa el hub (api-connector.md §2.3): el plugin 0.1.4 firma en v2 desde ya.
                'signature' => SiteSignatures::advertised(),
            ];
        });
    }
}
