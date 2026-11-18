<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Queries;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Actions\EvaluateTier;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Loyalty\Support\ProgramExplainer;
use Illuminate\Support\Collection;

/**
 * Estado de un contacto en el programa: saldos, nivel y avance al siguiente.
 * Lo usan el perfil 360 y «Mis recompensas» en la tienda.
 */
final class MemberStatus
{
    public function __construct(private readonly DatabaseLoyaltyLedger $ledger) {}

    /**
     * @return array{balances: array{points: int, pending: int, wallet: string, expiring: int, expiring_at: string|null}, tier: array<string, mixed>|null}
     */
    public function forContact(string $contactId): array
    {
        $program = $this->ledger->program();
        $this->ledger->absorbMerged($contactId);
        $balances = $this->ledger->summary($contactId);
        if ($program === null) {
            return ['balances' => $balances, 'tier' => null];
        }
        $tiers = LoyaltyTier::query()->where('program_id', $program->id)->orderBy('rank')->get();

        return ['balances' => $balances, 'tier' => self::tierProgress($contactId, $tiers)];
    }

    /**
     * @param  Collection<int, LoyaltyTier>  $tiers
     * @return array<string, mixed>|null
     */
    public static function tierProgress(string $contactId, Collection $tiers): ?array
    {
        if ($tiers->isEmpty()) {
            return null;
        }
        $metrics = EvaluateTier::metrics($contactId);
        $tierId = Contact::query()->whereKey($contactId)->value('tier_id');
        $current = $tierId !== null ? $tiers->firstWhere('id', $tierId) : null;
        $sorted = $tiers->sortBy('rank')->values();
        $next = $current === null ? $sorted->first() : $sorted->first(fn (LoyaltyTier $t) => $t->rank > $current->rank);
        $progress = 100;
        if ($next !== null) {
            $value = $metrics[$next->threshold_type];
            $from = $current !== null && $current->threshold_type === $next->threshold_type ? (float) $current->threshold : 0.0;
            $span = max(0.01, (float) $next->threshold - $from);
            $progress = (int) max(0, min(99, floor((($value - $from) / $span) * 100)));
        }

        return [
            'id' => $current?->publicId(),
            'name' => $current?->name,
            'benefits' => $current !== null ? ProgramExplainer::benefits($current) : [],
            'next_id' => $next?->publicId(),
            'next_name' => $next?->name,
            'next_threshold_type' => $next?->threshold_type,
            'next_threshold' => $next !== null ? (string) $next->threshold : null,
            'value' => $next !== null ? $metrics[$next->threshold_type] : null,
            'progress_pct' => $progress,
            'metrics' => $metrics,
        ];
    }
}
