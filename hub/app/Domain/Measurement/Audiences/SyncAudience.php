<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Measurement\Audiences\Models\AudienceMember;
use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Audiences\Platforms\AudiencePlatform;
use App\Domain\Measurement\Audiences\Platforms\AudiencePlatforms;
use App\Domain\Measurement\Conversions\DeliveryResult;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza un segmento con su audiencia de anuncios (hub/CLAUDE.md §11):
 * calcula quién debe estar (miembros del segmento que cumplen la regla de
 * consentimiento de AudienceEligibility y tienen correo o teléfono), lo compara
 * con lo último que se envió (`audience_members`) y manda solo las bajas y las
 * altas, por lotes. A quien cambió de correo o teléfono se le quitan los
 * identificadores viejos y se le suben los nuevos.
 *
 * Cada lote que la plataforma acepta queda registrado al momento: si un lote
 * falla, lo anterior ya cuenta y la próxima corrida retoma desde ahí.
 */
final class SyncAudience
{
    public function __construct(private readonly AudiencePlatforms $platforms, private readonly CurrentWorkspace $current) {}

    /** @return array{added: int, removed: int, members: int, skipped_no_consent: int, error: string|null} */
    public function handle(AudienceSync $sync, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $workspace = $this->current->get();
        $sync->forceFill(['last_attempt_at' => $now])->save();
        $destination = AdDestination::query()->find($sync->destination_id);
        if ($destination === null || ! $destination->isActive()) {
            return $this->fail($sync, __('El destino de anuncios ya no existe o está en pausa. Actívalo en Medición → Destinos.'));
        }
        $platform = $this->platforms->for($destination->platform);
        if (($missing = $platform->missing($destination)) !== null) {
            return $this->fail($sync, $missing);
        }
        if ($sync->platform_audience_id === null) {
            [$result, $audienceId] = $platform->create($destination, $sync);
            if (! $result->isDelivered() || $audienceId === null) {
                return $this->fail($sync, $result->message !== '' ? $result->message : __('La plataforma no creó la audiencia.'), $result);
            }
            $sync->forceFill(['platform_audience_id' => $audienceId])->save();
        }
        $audienceId = (string) $sync->platform_audience_id;

        [$desired, $skipped] = $this->desired($workspace, $sync, $platform);
        $current = [];
        foreach (AudienceMember::query()->where('audience_sync_id', $sync->id)->cursor() as $member) {
            /** @var AudienceMember $member */
            $current[$member->contact_id] = (array) $member->identifiers;
        }

        $remove = [];
        $add = [];
        foreach ($current as $contactId => $identifiers) {
            if (! isset($desired[$contactId]) || $desired[$contactId] != $identifiers) {
                $remove[$contactId] = $identifiers;
            }
        }
        foreach ($desired as $contactId => $identifiers) {
            if (! isset($current[$contactId]) || $current[$contactId] != $identifiers) {
                $add[$contactId] = $identifiers;
            }
        }

        $removed = 0;
        foreach (array_chunk($remove, $platform->batchSize(), true) as $batch) {
            $result = $platform->remove($destination, $audienceId, array_values(array_filter($batch)));
            if (! $result->isDelivered()) {
                return $this->fail($sync, $result->message, $result, count($current) - $removed, 0, $removed, $skipped);
            }
            AudienceMember::query()->where('audience_sync_id', $sync->id)->whereIn('contact_id', array_map('strval', array_keys($batch)))->delete();
            $removed += count($batch);
        }
        $members = count($current) - $removed;
        $added = 0;
        foreach (array_chunk($add, $platform->batchSize(), true) as $batch) {
            $result = $platform->add($destination, $audienceId, array_values($batch));
            if (! $result->isDelivered()) {
                return $this->fail($sync, $result->message, $result, $members, $added, $removed, $skipped);
            }
            DB::transaction(function () use ($sync, $batch, $now): void {
                foreach (array_chunk($batch, 500, true) as $rows) {
                    $insert = [];
                    foreach ($rows as $contactId => $identifiers) {
                        // Igual que el cast encrypted:array de AudienceMember, en una sola inserción por lote.
                        $insert[] = ['audience_sync_id' => $sync->id, 'contact_id' => (string) $contactId, 'identifiers' => Crypt::encryptString((string) json_encode($identifiers)), 'synced_at' => $now];
                    }
                    AudienceMember::query()->insert($insert);
                }
            });
            $added += count($batch);
            $members += count($batch);
        }

        $sync->forceFill([
            'status' => $sync->status === 'paused' ? 'paused' : 'active',
            'members_count' => $members, 'last_added' => $added, 'last_removed' => $removed, 'skipped_no_consent' => $skipped,
            'last_synced_at' => $now, 'last_error' => null,
        ])->save();
        Log::info('igr.audiences.synced', ['audience' => $sync->id, 'added' => $added, 'removed' => $removed, 'members' => $members]);

        return ['added' => $added, 'removed' => $removed, 'members' => $members, 'skipped_no_consent' => $skipped, 'error' => null];
    }

    /**
     * Quién debe estar: miembros vivos del segmento que cumplen la regla de
     * consentimiento, con sus identificadores para esta plataforma.
     *
     * @return array{0: array<string, array<string, string>>, 1: int} identificadores por contacto y cuántos quedaron fuera por consentimiento
     */
    private function desired(Workspace $workspace, AudienceSync $sync, AudiencePlatform $platform): array
    {
        $base = DB::table('segment_memberships as m')->join('contacts as c', 'c.id', '=', 'm.contact_id')
            ->where('m.workspace_id', $workspace->id)->where('m.segment_id', $sync->segment_id)
            ->where('c.workspace_id', $workspace->id)->whereNull('c.deleted_at')->whereNull('c.merged_into_id');
        $alive = (clone $base)->count();
        $country = (string) $workspace->setting('default_phone_country', $workspace->country ?: 'CO');

        $desired = [];
        AudienceEligibility::apply(clone $base, $workspace)->orderBy('c.id')->select('c.id')
            ->chunk(1000, function ($rows) use (&$desired, $platform, $country): void {
                $contacts = Contact::query()->whereIn('id', $rows->pluck('id')->all())->get(['id', 'email', 'phone', 'country']);
                foreach ($contacts as $contact) {
                    $identifiers = $platform->identifiers($contact->email, $contact->phone, (string) ($contact->getAttribute('country') ?: $country));
                    if ($identifiers !== []) {
                        ksort($identifiers);
                        $desired[$contact->id] = $identifiers;
                    }
                }
            });
        $eligible = AudienceEligibility::apply(clone $base, $workspace)->count();

        return [$desired, max(0, $alive - $eligible)];
    }

    /** @return array{added: int, removed: int, members: int, skipped_no_consent: int, error: string} */
    private function fail(AudienceSync $sync, string $message, ?DeliveryResult $result = null, ?int $members = null, int $added = 0, int $removed = 0, ?int $skipped = null): array
    {
        $retry = $result !== null && $result->status === DeliveryResult::RETRY;
        $sync->forceFill(array_filter([
            // Un error temporal no marca la audiencia como rota: se reintenta en la próxima corrida.
            'status' => $retry || $sync->status === 'paused' ? $sync->status : 'error',
            'last_error' => mb_substr($message !== '' ? $message : __('La plataforma rechazó la sincronización.'), 0, 500),
            'members_count' => $members,
            'last_added' => $added,
            'last_removed' => $removed,
            'skipped_no_consent' => $skipped,
        ], fn ($v) => $v !== null))->save();
        Log::warning('igr.audiences.failed', ['audience' => $sync->id, 'code' => $result?->errorCode]);

        return ['added' => $added, 'removed' => $removed, 'members' => $members ?? $sync->members_count, 'skipped_no_consent' => $skipped ?? $sync->skipped_no_consent, 'error' => $message];
    }
}
