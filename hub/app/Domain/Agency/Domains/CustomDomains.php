<?php

declare(strict_types=1);

namespace App\Domain\Agency\Domains;

use App\Domain\Agency\Models\CustomDomain;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dominios propios de la agencia (M22, marca blanca): el del panel
 * (`panel.agencia.com`) o los de enlaces y agenda de un cliente. Se agregan →
 * se muestran los registros DNS (CNAME hacia el hub y un TXT con el token) →
 * se verifican → Caddy emite el certificado solo para los verificados
 * (`servable`, que consulta el «ask» de TLS bajo demanda).
 */
final class CustomDomains
{
    public const TXT_PREFIX = '_igr-verify.';

    public function __construct(private readonly DnsResolver $dns, private readonly RecordAuditLog $audit) {}

    public static function cnameTarget(): string
    {
        return strtolower((string) config('igr.custom_domains.cname_target', 'growth.imagina.cloud'));
    }

    public function add(string $accountId, ?string $workspaceId, string $host, string $purpose, ?string $userId = null): CustomDomain
    {
        $host = self::normalize($host);
        if ($host === null) {
            throw ValidationException::withMessages(['host' => __('Escribe un dominio válido, por ejemplo panel.tuagencia.com.')]);
        }
        $own = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($own !== '' && ($host === $own || str_ends_with($host, '.'.$own) || str_ends_with($host, '.'.self::cnameTarget()) || $host === self::cnameTarget())) {
            throw ValidationException::withMessages(['host' => __('Ese dominio es de la plataforma.')]);
        }
        if (! in_array($purpose, CustomDomain::PURPOSES, true)) {
            throw ValidationException::withMessages(['purpose' => __('Uso de dominio desconocido.')]);
        }
        if (CustomDomain::query()->where('host', $host)->exists()) {
            throw ValidationException::withMessages(['host' => __('Ese dominio ya está registrado.')]);
        }
        $domain = CustomDomain::query()->create([
            'account_id' => $accountId,
            'workspace_id' => $workspaceId,
            'host' => $host,
            'purpose' => $purpose,
            'status' => 'pending',
            'verification_token' => Str::lower(Str::random(32)),
        ]);
        $this->audit->handle('agency.domain_added', $domain, ['host' => $host, 'purpose' => $purpose], $workspaceId, $accountId, $userId);

        return $domain;
    }

    /** @return list<array{type: string, name: string, value: string}> */
    public static function instructions(CustomDomain $domain): array
    {
        return [
            ['type' => 'CNAME', 'name' => $domain->host, 'value' => self::cnameTarget()],
            ['type' => 'TXT', 'name' => self::TXT_PREFIX.$domain->host, 'value' => 'igr-verify='.$domain->verification_token],
        ];
    }

    public function verify(CustomDomain $domain, ?string $userId = null): CustomDomain
    {
        $txtOk = in_array('igr-verify='.$domain->verification_token, array_map('trim', $this->dns->txt(self::TXT_PREFIX.$domain->host)), true);
        $cnameOk = in_array(self::cnameTarget(), array_map(fn (string $c) => rtrim(strtolower($c), '.'), $this->dns->cname($domain->host)), true);
        $error = match (true) {
            ! $txtOk => __('No encontramos el registro TXT con el código de verificación. Los cambios de DNS pueden tardar hasta 24 horas.'),
            ! $cnameOk => __('El registro CNAME no apunta a :target.', ['target' => self::cnameTarget()]),
            default => null,
        };
        $domain->forceFill([
            'status' => $error === null ? 'verified' : ($domain->status === 'verified' ? 'verified' : 'failed'),
            'verified_at' => $error === null ? ($domain->verified_at ?? now()) : $domain->verified_at,
            'last_error' => $error,
        ])->save();
        Cache::forget(self::cacheKey($domain->host));
        if ($error === null) {
            $this->audit->handle('agency.domain_verified', $domain, ['host' => $domain->host], $domain->workspace_id, $domain->account_id, $userId);
        }

        return $domain;
    }

    public function remove(CustomDomain $domain, ?string $userId = null): void
    {
        $this->audit->handle('agency.domain_removed', $domain, ['host' => $domain->host], $domain->workspace_id, $domain->account_id, $userId);
        Cache::forget(self::cacheKey($domain->host));
        $domain->delete();
    }

    /** ¿Caddy puede emitir un certificado para este host? Solo dominios verificados. */
    public static function servable(string $host): bool
    {
        $host = self::normalize($host);
        if ($host === null) {
            return false;
        }

        return (bool) Cache::remember(self::cacheKey($host), 60, fn (): bool => CustomDomain::query()->where('host', $host)->where('status', 'verified')->exists());
    }

    public static function normalize(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('#^https?://#', '', $host);
        $host = rtrim(explode('/', $host)[0], '.');
        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $label = '(?!-)[a-z0-9-]{1,63}(?<!-)';

        return preg_match('/^('.$label.'\.)+[a-z]{2,63}$/', $host) === 1 ? $host : null;
    }

    private static function cacheKey(string $host): string
    {
        return 'igr:agency:domain:'.sha1($host);
    }
}
