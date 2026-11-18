<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Jobs\RecalculateContactStats;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Contacts\Models\ContactMerge;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fusiona dos contactos que comparten un identificador fuerte (eventos.md
 * §6.2). El perdedor queda con merged_into_id y todo lo que se movió queda
 * en contact_merges.snapshot para poder revertir durante 30 días.
 *
 * Los eventos de ClickHouse no se reescriben: se consultan por el ganador y
 * sus perdedores, y el mapa anónimo → contacto apunta al ganador (el llamador
 * escribe esas filas de identity_map con lo que devuelve esta acción).
 */
final class MergeContacts
{
    private const PROFILE_FIELDS = ['email', 'email_bidx', 'phone', 'phone_bidx', 'first_name', 'last_name', 'name_prefix_bidx', 'city', 'region_code', 'country', 'locale', 'timezone', 'birthday', 'hdyhau'];

    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @return array{merge: ContactMerge, anonymous_ids: list<string>}
     */
    public function handle(Contact $winner, Contact $loser, string $reason, ?string $userId = null): array
    {
        if ($winner->id === $loser->id || $winner->workspace_id !== $loser->workspace_id) {
            throw new InvalidArgumentException('No se puede fusionar un contacto consigo mismo ni entre espacios de trabajo.');
        }
        if ($loser->getAttribute('merged_into_id') !== null || $winner->getAttribute('merged_into_id') !== null) {
            throw new InvalidArgumentException('Uno de los contactos ya está fusionado.');
        }

        $result = DB::transaction(function () use ($winner, $loser, $reason): array {
            $identities = ContactIdentity::query()->where('contact_id', $loser->id)->get(['id', 'type', 'value']);
            $orders = Order::query()->where('contact_id', $loser->id)->pluck('id')->all();
            $notes = DB::table('contact_notes')->where('workspace_id', $winner->workspace_id)->where('contact_id', $loser->id)->pluck('id')->all();
            $surveys = DB::table('survey_responses')->where('workspace_id', $winner->workspace_id)->where('contact_id', $loser->id)->pluck('id')->all();

            ContactIdentity::query()->whereIn('id', $identities->modelKeys())->update(['contact_id' => $winner->id]);
            Order::query()->whereIn('id', $orders)->update(['contact_id' => $winner->id]);
            DB::table('contact_notes')->whereIn('id', $notes)->update(['contact_id' => $winner->id]);
            DB::table('survey_responses')->whereIn('id', $surveys)->update(['contact_id' => $winner->id]);
            DB::table('segment_memberships')->where('workspace_id', $winner->workspace_id)->where('contact_id', $loser->id)->delete();
            $consents = $this->mergeConsents($winner, $loser);

            // El ganador completa los datos que no tenía.
            $previous = [];
            foreach (self::PROFILE_FIELDS as $field) {
                $mine = $winner->getAttribute($field);
                $theirs = $loser->getAttribute($field);
                if (($mine === null || $mine === '') && $theirs !== null && $theirs !== '') {
                    $previous[$field] = $mine;
                    $winner->setAttribute($field, $theirs);
                }
            }
            foreach (['first_seen_at' => 'min', 'last_seen_at' => 'max'] as $field => $fn) {
                $a = $winner->getAttribute($field);
                $b = $loser->getAttribute($field);
                if ($b !== null && ($a === null || ($fn === 'min' ? $b->lt($a) : $b->gt($a)))) {
                    $previous[$field] = $a?->toIso8601String();
                    $winner->setAttribute($field, $b);
                }
            }
            $tags = array_values(array_unique(array_merge((array) ($winner->getAttribute('tags') ?? []), (array) ($loser->getAttribute('tags') ?? []))));
            if ($tags !== (array) ($winner->getAttribute('tags') ?? [])) {
                $previous['tags'] = $winner->getAttribute('tags');
                $winner->setAttribute('tags', $tags);
            }
            // El correo y el teléfono se buscan por índice ciego: el perdedor los suelta (se restauran al revertir).
            $loserBidx = ['email_bidx' => $loser->getAttribute('email_bidx'), 'phone_bidx' => $loser->getAttribute('phone_bidx')];
            $loser->forceFill(['email_bidx' => null, 'phone_bidx' => null]);
            $winner->save();
            $loser->forceFill(['merged_into_id' => $winner->id])->save();

            $merge = ContactMerge::query()->create([
                'workspace_id' => $winner->workspace_id,
                'winner_id' => $winner->id,
                'loser_id' => $loser->id,
                'reason' => mb_substr($reason, 0, 64),
                'snapshot' => [
                    'identities' => $identities->modelKeys(),
                    'orders' => $orders,
                    'notes' => $notes,
                    'surveys' => $surveys,
                    'consents' => $consents,
                    'winner_previous' => $this->encryptPrevious($previous),
                    'loser_bidx' => $loserBidx,
                ],
            ]);

            $anonymous = [];
            foreach ($identities as $identity) {
                if ($identity->type === 'anonymous_id') {
                    $anonymous[] = (string) $identity->value;
                }
            }

            return ['merge' => $merge, 'anonymous_ids' => $anonymous];
        });

        RecalculateContactStats::debounce($winner->workspace_id, $winner->id);
        $this->audit->handle('contact.merged', $result['merge'], ['winner' => $winner->publicId(), 'loser' => $loser->publicId(), 'reason' => $reason], $winner->workspace_id, null, $userId);

        return $result;
    }

    /**
     * Consentimiento: si ambos tienen el mismo canal y finalidad, gana la acción
     * más reciente (una revocación nunca se pierde en una fusión).
     *
     * @return array{moved: list<string>, winner_before: array<string, array<string, mixed>>}
     */
    private function mergeConsents(Contact $winner, Contact $loser): array
    {
        $moved = [];
        $before = [];
        $mine = DB::table('contact_consents')->where('workspace_id', $winner->workspace_id)->where('contact_id', $winner->id)->get()->keyBy(fn ($r) => $r->channel.'|'.$r->purpose);
        foreach (DB::table('contact_consents')->where('workspace_id', $winner->workspace_id)->where('contact_id', $loser->id)->get() as $row) {
            $key = $row->channel.'|'.$row->purpose;
            $existing = $mine->get($key);
            if ($existing === null) {
                DB::table('contact_consents')->where('id', $row->id)->update(['contact_id' => $winner->id]);
                $moved[] = (string) $row->id;

                continue;
            }
            $lastOf = fn ($r) => max((string) ($r->granted_at ?? ''), (string) ($r->revoked_at ?? ''));
            if ($lastOf($row) > $lastOf($existing)) {
                $before[(string) $existing->id] = (array) $existing;
                DB::table('contact_consents')->where('id', $existing->id)->update([
                    'status' => $row->status, 'source' => $row->source, 'legal_text_version' => $row->legal_text_version,
                    'granted_at' => $row->granted_at, 'revoked_at' => $row->revoked_at, 'updated_at' => now(),
                ]);
            }
        }

        return ['moved' => $moved, 'winner_before' => $before];
    }

    /**
     * Los valores previos pueden ser datos personales: se guardan cifrados.
     *
     * @param  array<string, mixed>  $previous
     */
    private function encryptPrevious(array $previous): ?string
    {
        return $previous === [] ? null : encrypt($previous);
    }
}
