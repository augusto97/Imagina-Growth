<?php

declare(strict_types=1);

namespace App\Domain\Sites\Actions;

use App\Domain\Sites\Models\SiteConnectCode;
use App\Domain\Sites\Support\SiteKeys;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * Crea un código de conexión (api-connector.md §3.1 y §3.2).
 */
final class CreateConnectCode
{
    public function __construct(private RecordAuditLog $audit) {}

    /**
     * @param  array{site_url?: string|null, nonce?: string|null, return_url?: string|null, platform?: string|null, name?: string|null}  $input
     * @return array{code: string, redirect_url: string|null, expires_at: string}
     */
    public function handle(Workspace $workspace, User $user, array $input): array
    {
        $siteUrl = $input['site_url'] ?? null;
        $returnUrl = $input['return_url'] ?? null;
        $siteHost = $siteUrl ? SiteKeys::host($siteUrl) : null;

        if ($siteUrl !== null && $siteHost === null) {
            throw ValidationException::withMessages(['site_url' => __('La URL del sitio no es válida.')]);
        }
        if ($returnUrl !== null) {
            // El regreso solo puede ir al mismo sitio que pidió conectarse.
            if ($siteHost === null || SiteKeys::host($returnUrl) !== $siteHost) {
                throw ValidationException::withMessages(['return_url' => __('La URL de regreso no pertenece al sitio.')]);
            }
            $this->assertScheme($returnUrl, 'return_url');
        }
        if ($siteUrl !== null) {
            $this->assertScheme($siteUrl, 'site_url');
        }

        $code = SiteKeys::connectCode();
        $record = SiteConnectCode::query()->create([
            'workspace_id' => $workspace->id,
            'code_hash' => SiteKeys::hashCode($code),
            'site_url' => $siteUrl,
            'site_host' => $siteHost,
            'nonce' => $input['nonce'] ?? null,
            'return_url' => $returnUrl,
            'platform' => $input['platform'] ?? null,
            'name' => $input['name'] ?? null,
            'created_by' => $user->id,
            'expires_at' => now()->addMinutes((int) config('igr.connect.code_ttl_minutes')),
        ]);

        $this->audit->handle('site.connect_code_created', $record, ['site_host' => $siteHost, 'manual' => $siteUrl === null], $workspace->id, $workspace->account_id, $user->id);

        $redirect = null;
        if ($returnUrl !== null) {
            $sep = str_contains($returnUrl, '?') ? '&' : '?';
            $redirect = $returnUrl.$sep.http_build_query(['igr_code' => $code, 'igr_nonce' => $input['nonce'] ?? '']);
        }

        return ['code' => $code, 'redirect_url' => $redirect, 'expires_at' => $record->expires_at->toIso8601String()];
    }

    private function assertScheme(string $url, string $field): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $allowed = config('igr.connect.allow_insecure_site_urls') ? ['https', 'http'] : ['https'];
        if (! in_array($scheme, $allowed, true)) {
            throw ValidationException::withMessages([$field => __('El sitio debe usar HTTPS.')]);
        }
    }
}
