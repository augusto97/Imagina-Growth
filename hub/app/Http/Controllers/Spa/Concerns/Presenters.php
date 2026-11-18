<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa\Concerns;

use App\Domain\Agency\Support\AgencyAccess;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Formas JSON compartidas por los controladores de la SPA (IDs con prefijo). */
final class Presenters
{
    /** @return array<string, mixed> */
    public static function workspace(Workspace $ws, ?WorkspaceRole $role = null): array
    {
        return [
            'id' => $ws->publicId(),
            'name' => $ws->name,
            'slug' => $ws->slug,
            'account_id' => 'acc_'.$ws->account_id,
            'country' => $ws->country,
            'timezone' => $ws->timezone,
            'currency' => $ws->currency,
            'locale' => $ws->locale,
            'industry' => $ws->getAttribute('industry'),
            'legal_name' => $ws->getAttribute('legal_name'),
            'privacy_policy_url' => $ws->getAttribute('privacy_policy_url'),
            'settings' => $ws->settings ?? [],
            'status' => $ws->status,
            'role' => $role?->value,
            // M22: `viewAgency` llega por la cuenta de agencia, no por el rol.
            'abilities' => $role === null ? [] : array_values(array_unique([
                ...$role->grantedAbilities(), ...AgencyAccess::extraAbilities(self::user(), $ws),
                // M21: el panel interno de la plataforma, solo para el equipo de Imagina.
                ...((bool) (self::user()?->getAttributes()['is_platform_admin'] ?? false) ? ['platformAdmin'] : []),
            ])),
            // M21: estado de facturación de la cuenta, para el aviso de gracia o suspensión en todo el panel.
            'billing' => self::billing($ws),
            'branding' => self::branding($ws),
            'requires_two_factor' => $role?->requiresTwoFactor() ?? false,
        ];
    }

    /** @return array{status: string, grace_ends_at: string|null, trial_ends_at: string|null} */
    private static function billing(Workspace $ws): array
    {
        $account = DB::table('accounts')->where('id', $ws->account_id)->first(['billing_status', 'grace_ends_at', 'trial_ends_at']);
        $iso = fn (mixed $v) => $v !== null ? CarbonImmutable::parse((string) $v)->toIso8601String() : null;

        return [
            'status' => (string) ($account->billing_status ?? 'active'),
            'grace_ends_at' => $iso($account->grace_ends_at ?? null),
            'trial_ends_at' => $iso($account->trial_ends_at ?? null),
        ];
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * La marca que ve el panel en este espacio de trabajo (M22, marca blanca).
     *
     * @return array{name: string, logo_url: string|null, primary_color: string, white_label: bool}
     */
    public static function branding(Workspace $ws): array
    {
        $b = app(Branding::class)->for($ws);

        return ['name' => $b->name, 'logo_url' => $b->logoUrl, 'primary_color' => $b->primaryColor, 'white_label' => $b->whiteLabel];
    }

    /** @return array<string, mixed> */
    public static function site(Site $site, bool $detail = false): array
    {
        $data = [
            'id' => $site->publicId(),
            'name' => $site->name,
            'url' => $site->url,
            'host' => $site->host,
            'platform' => $site->platform,
            'status' => $site->status,
            'plugin_version' => $site->getAttribute('plugin_version'),
            'wp_version' => $site->getAttribute('wp_version'),
            'wc_version' => $site->getAttribute('wc_version'),
            'connected_at' => $site->connected_at?->toIso8601String(),
            'last_seen_at' => $site->last_seen_at?->toIso8601String(),
            'last_event_at' => $site->last_event_at?->toIso8601String(),
            'backfill' => self::backfill($site->backfill_state),
        ];
        if ($detail) {
            $data += [
                'public_key' => $site->public_key,
                'rest_url' => $site->rest_url,
                'timezone' => $site->timezone,
                'currency' => $site->currency,
                'php_version' => $site->getAttribute('php_version'),
                'capabilities' => $site->capabilities ?? [],
                'settings' => $site->effectiveSettings(),
                'health' => $site->health ?? [],
                'secret_rotated_at' => $site->getAttribute('secret_rotated_at')?->toIso8601String(),
            ];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array<string, mixed>|null
     */
    public static function backfill(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }
        $entities = is_array($state['entities'] ?? null) ? $state['entities'] : [];
        $total = 0;
        $sent = 0;
        foreach ($entities as $e) {
            $total += (int) ($e['total'] ?? 0);
            $sent += (int) ($e['sent'] ?? 0);
        }

        return [
            'status' => $state['status'] ?? null,
            'phase' => $state['phase'] ?? null,
            'from' => $state['from'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
            'entities' => $entities,
            'progress' => $total > 0 ? round($sent / $total, 4) : null,
            'error' => $state['error'] ?? null,
            'reported_at' => $state['reported_at'] ?? null,
        ];
    }
}
