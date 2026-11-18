<?php

declare(strict_types=1);

namespace App\Domain\Consent\Actions;

use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Consentimientos que llegan con los eventos (cumplimiento.md §3.1):
 * casillas del checkout en order.consents y casillas mapeadas de formularios
 * en form.submitted.consents. Solo una casilla marcada otorga: una sin marcar
 * no revoca nada (la revocación es explícita: baja, palabra clave, centro de
 * preferencias o el panel).
 */
final class ProjectConsents
{
    /** Clave de la casilla => canal. */
    private const KEYS = ['email_marketing' => 'email', 'whatsapp_marketing' => 'whatsapp', 'sms_marketing' => 'sms'];

    public function __construct(private readonly RecordConsent $record) {}

    /**
     * La decisión del banner de cookies (`context.consent.marketing`, con un banner o CMP
     * real: `source` distinto de `none`) de un visitante identificado se guarda como su
     * consentimiento `ads`: aceptar las cookies de marketing lo deja entrar a las audiencias
     * de anuncios en modo estricto y rechazarlas lo saca (M06, cumplimiento.md §3.3)
     * [validar con el abogado]. Solo se escribe cuando cambia: el último estado visto por
     * contacto vive un día en Redis, así que un visitante que navega no escribe en cada página.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function projectBanner(Workspace $workspace, string $contactId, array $envelope, ?string $userAgent): void
    {
        $banner = $envelope['context']['consent'] ?? null;
        if (! is_array($banner) || ! is_bool($banner['marketing'] ?? null)) {
            return;
        }
        $cmp = (string) ($banner['source'] ?? 'none');
        if ($cmp === '' || $cmp === 'none') {
            return;
        }
        $action = $banner['marketing'] ? 'granted' : 'revoked';
        $key = RedisKey::for($workspace->id, 'adsc:'.$contactId);
        if (Cache::get($key) === $action) {
            return;
        }
        $this->record->handle($workspace, $contactId, 'ads', 'marketing', $action, 'banner:'.mb_substr($cmp, 0, 40), null,
            CarbonImmutable::parse((string) $envelope['occurred_at'])->utc(), array_filter([
                'event_id' => $envelope['event_id'] ?? null,
                'event' => $envelope['event'] ?? null,
                'page_url' => $envelope['context']['page']['url'] ?? null,
                'ua_hash' => RecordConsent::evidenceHash($userAgent),
            ], fn ($v) => $v !== null));
        Cache::put($key, $action, now()->addDay());
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function project(Workspace $workspace, string $contactId, array $envelope, ?string $ip, ?string $userAgent): void
    {
        $event = (string) ($envelope['event'] ?? '');
        $props = is_array($envelope['properties'] ?? null) ? $envelope['properties'] : [];
        $consents = null;
        $source = null;
        $version = null;
        $evidence = [
            'event_id' => $envelope['event_id'] ?? null,
            'event' => $event,
            'page_url' => $envelope['context']['page']['url'] ?? null,
            'ip_hash' => RecordConsent::evidenceHash($ip),
            'ua_hash' => RecordConsent::evidenceHash($userAgent),
        ];

        if (str_starts_with($event, 'order.') && is_array($props['order']['consents'] ?? null)) {
            $consents = $props['order']['consents'];
            $source = 'checkout';
            $version = isset($consents['text_version']) ? (string) $consents['text_version'] : null;
            $evidence['order_id'] = $props['order']['order_id'] ?? null;
        } elseif ($event === 'form.submitted' && is_array($props['consents'] ?? null)) {
            $consents = $props['consents'];
            $source = 'form:'.mb_substr((string) ($props['form_plugin'] ?? 'form'), 0, 20).':'.mb_substr((string) ($props['form_id'] ?? ''), 0, 30);
            $evidence['page_url'] ??= $props['page_path'] ?? null;
            $evidence['form_snapshot'] = ['plugin' => $props['form_plugin'] ?? null, 'form_id' => $props['form_id'] ?? null, 'form_name' => $props['form_name'] ?? null];
        }
        if ($consents === null || $source === null) {
            return;
        }

        $at = CarbonImmutable::parse((string) $envelope['occurred_at'])->utc();
        foreach (self::KEYS as $key => $channel) {
            if (($consents[$key] ?? null) === true) {
                $this->record->handle($workspace, $contactId, $channel, 'marketing', 'granted', $source, $version, $at, array_filter($evidence, fn ($v) => $v !== null));
            }
        }
    }
}
