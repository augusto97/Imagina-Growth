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
use Illuminate\Validation\ValidationException;

/** Revierte una fusión dentro de los 30 días (eventos.md §6.2, M03 «una fusión se revierte sin perder eventos»). */
final class RevertContactMerge
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @return list<string> anonymous_ids que vuelven al contacto separado (para identity_map)
     */
    public function handle(ContactMerge $merge, ?string $userId = null): array
    {
        if (! $merge->canRevert()) {
            throw ValidationException::withMessages(['merge' => __('Esta fusión ya no se puede revertir.')]);
        }
        $snapshot = $merge->snapshot ?? [];

        $anonymous = DB::transaction(function () use ($merge, $snapshot): array {
            /** @var Contact $winner */
            $winner = Contact::query()->findOrFail($merge->winner_id);
            /** @var Contact $loser */
            $loser = Contact::query()->findOrFail($merge->loser_id);

            ContactIdentity::query()->whereIn('id', (array) ($snapshot['identities'] ?? []))->update(['contact_id' => $loser->id]);
            Order::query()->whereIn('id', (array) ($snapshot['orders'] ?? []))->update(['contact_id' => $loser->id]);
            DB::table('contact_notes')->whereIn('id', (array) ($snapshot['notes'] ?? []))->update(['contact_id' => $loser->id]);
            DB::table('survey_responses')->whereIn('id', (array) ($snapshot['surveys'] ?? []))->update(['contact_id' => $loser->id]);
            DB::table('contact_consents')->whereIn('id', (array) ($snapshot['consents']['moved'] ?? []))->update(['contact_id' => $loser->id]);
            foreach ((array) ($snapshot['consents']['winner_before'] ?? []) as $id => $row) {
                DB::table('contact_consents')->where('id', $id)->update(array_intersect_key((array) $row, array_flip(['status', 'source', 'legal_text_version', 'granted_at', 'revoked_at'])));
            }

            $previous = isset($snapshot['winner_previous']) && is_string($snapshot['winner_previous']) ? (array) decrypt($snapshot['winner_previous']) : [];
            foreach ($previous as $field => $value) {
                $winner->setAttribute((string) $field, $value);
            }
            $winner->save();
            $loser->forceFill(['merged_into_id' => null] + array_filter((array) ($snapshot['loser_bidx'] ?? []), fn ($v) => $v !== null))->save();
            $merge->forceFill(['reverted_at' => now()])->save();

            return ContactIdentity::query()->whereIn('id', (array) ($snapshot['identities'] ?? []))->where('type', 'anonymous_id')->pluck('value')->map(fn ($v) => (string) $v)->all();
        });

        RecalculateContactStats::debounce($merge->workspace_id, $merge->winner_id);
        RecalculateContactStats::debounce($merge->workspace_id, $merge->loser_id);
        $this->audit->handle('contact.merge_reverted', $merge, [], $merge->workspace_id, null, $userId);

        return array_values($anonymous);
    }
}
