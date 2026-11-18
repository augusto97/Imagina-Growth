<?php

declare(strict_types=1);

namespace App\Domain\Consent\Actions;

use App\Domain\Consent\Models\ConsentEvent;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registra un consentimiento o una revocación con su evidencia
 * (cumplimiento.md §2 y §3). Idempotente: repetir el mismo estado con la
 * misma versión no crea otra prueba. Una acción más vieja que la vigente no la
 * pisa (los eventos pueden llegar fuera de orden). Emite consent.granted o
 * consent.revoked después del commit.
 */
final class RecordConsent
{
    public function __construct(private readonly HubEvents $hubEvents) {}

    /**
     * @param  'granted'|'revoked'  $action
     * @param  array<string, mixed>  $evidence  page_url, ip_hash, ua_hash, event_id, form_snapshot…
     * @param  bool  $authoritative  acción directa de la persona o del equipo (panel, baja): gana aunque
     *                               el reloj del sitio haya fechado la anterior unos segundos en el futuro
     * @return bool si cambió el estado vigente
     */
    public function handle(
        Workspace $workspace,
        string $contactId,
        string $channel,
        string $purpose,
        string $action,
        string $source,
        ?string $legalTextVersion,
        CarbonInterface $at,
        array $evidence = [],
        bool $authoritative = false,
    ): bool {
        if (! in_array($channel, ContactConsent::CHANNELS, true) || ! in_array($purpose, ['marketing', 'transactional'], true) || ! in_array($action, ['granted', 'revoked'], true)) {
            throw new InvalidArgumentException("Consentimiento inválido: {$channel}/{$purpose}/{$action}");
        }

        /** @var ContactConsent|null $current */
        $current = ContactConsent::query()->where('contact_id', $contactId)->where('channel', $channel)->where('purpose', $purpose)->first();
        if ($current !== null) {
            $same = $current->status === $action && ($action === 'revoked' || $current->legal_text_version === $legalTextVersion);
            $last = $current->lastActionAt();
            if ($same || (! $authoritative && $last !== null && $last->greaterThan($at))) {
                return false;
            }
            if ($authoritative && $last !== null && $last->greaterThanOrEqualTo($at)) {
                $at = \Carbon\CarbonImmutable::instance($last)->addSecond();
            }
        }

        $attributes = [
            'workspace_id' => $workspace->id,
            'contact_id' => $contactId,
            'channel' => $channel,
            'purpose' => $purpose,
            'status' => $action,
            'source' => mb_substr($source, 0, 64),
            'legal_text_version' => $action === 'granted' ? $legalTextVersion : ($current?->legal_text_version),
        ];
        $attributes[$action === 'granted' ? 'granted_at' : 'revoked_at'] = $at;
        if ($current === null) {
            ContactConsent::query()->create($attributes);
        } else {
            $current->forceFill($attributes)->save();
        }

        ConsentEvent::query()->create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contactId,
            'channel' => $channel,
            'purpose' => $purpose,
            'action' => $action,
            'source' => mb_substr($source, 0, 64),
            'legal_text_version' => $legalTextVersion,
            'evidence' => $evidence === [] ? null : $evidence,
            'occurred_at' => $at,
        ]);

        // La revocación aplica de inmediato en todo el sistema (cumplimiento.md §9); el evento sale tras el commit.
        DB::afterCommit(function () use ($workspace, $action, $channel, $purpose, $source, $legalTextVersion, $contactId, $at): void {
            try {
                $this->hubEvents->emit($workspace, 'consent.'.$action, [
                    'channel' => $channel,
                    'purpose' => $purpose,
                    'source' => mb_substr($source, 0, 64),
                    'legal_text_version' => $legalTextVersion,
                ], $contactId, null, $at);
            } catch (\Throwable $e) {
                // El consentimiento ya quedó guardado; el evento analítico no debe deshacerlo.
                report($e);
            }
        });

        return true;
    }

    /** Hash de la IP o el user agent para la evidencia (nunca en claro, CLAUDE.md §3.9). */
    public static function evidenceHash(?string $value): ?string
    {
        return $value === null || $value === '' ? null : hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
