<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Loyalty\Jobs\SyncTierToSite;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Ingest\HubEvents;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Nivel VIP de un contacto (modulos.md M13): el de mayor rango cuyo umbral
 * alcanza en los últimos 12 meses (gasto neto de reembolsos, puntos ganados o
 * pedidos pagados). Si cambia: guarda `contacts.tier_id`, emite `tier.changed`
 * y manda `customer.set_tier` a cada sitio donde el contacto tiene cuenta.
 */
final class EvaluateTier
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly HubEvents $events) {}

    /**
     * @param  Collection<int, LoyaltyTier>|null  $tiers  los niveles del programa (para recorrer muchos contactos)
     * @return array{changed: bool, tier: LoyaltyTier|null}
     */
    public function handle(string $contactId, ?Collection $tiers = null, ?CarbonImmutable $now = null): array
    {
        $program = LoyaltyProgram::query()->first();
        if ($program === null || ! $program->isActive()) {
            return ['changed' => false, 'tier' => null];
        }
        $tiers ??= LoyaltyTier::query()->where('program_id', $program->id)->orderBy('rank')->get();
        $contact = Contact::query()->whereKey($contactId)->first(['id', 'tier_id', 'merged_into_id']);
        if ($contact === null || $contact->getAttribute('merged_into_id') !== null || $tiers->isEmpty()) {
            return ['changed' => false, 'tier' => null];
        }
        $metrics = self::metrics($contactId, $now);
        $next = null;
        foreach ($tiers->sortByDesc('rank') as $tier) {
            if ($metrics[$tier->threshold_type] >= (float) $tier->threshold) {
                $next = $tier;
                break;
            }
        }
        $previousId = $contact->getAttribute('tier_id');
        if (($next?->id) === $previousId) {
            return ['changed' => false, 'tier' => $next];
        }
        $previous = $previousId !== null ? $tiers->firstWhere('id', $previousId) : null;
        DB::table('contacts')->where('id', $contactId)->where('workspace_id', $this->current->id())->update(['tier_id' => $next?->id]);

        if ($next !== null) {
            $direction = $previous === null || $next->rank > $previous->rank ? 'up' : 'down';
            try {
                $this->events->emit($this->current->get(), 'tier.changed', [
                    'from' => $previous?->publicId(), 'to' => $next->publicId(),
                    'from_name' => $previous?->name, 'to_name' => $next->name, 'direction' => $direction,
                ], $contactId);
            } catch (Throwable $e) {
                report($e);
            }
            foreach (self::customerRefs($contactId) as [$siteId, $ref]) {
                SyncTierToSite::dispatch($this->current->id(), $siteId, $ref, $next->id);
            }
        }

        return ['changed' => true, 'tier' => $next];
    }

    /**
     * Métricas de los últimos 12 meses.
     *
     * @return array{spend_12m: float, points_12m: float, orders_12m: float}
     */
    public static function metrics(string $contactId, ?CarbonImmutable $now = null): array
    {
        $since = ($now ?? CarbonImmutable::now())->subMonths(12);
        $orders = DB::table('orders')->where('workspace_id', app(CurrentWorkspace::class)->id())->where('contact_id', $contactId)
            ->where('is_paid', true)->whereNotIn('status', ['cancelled', 'refunded', 'failed'])->where('paid_at', '>=', $since)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(CASE WHEN total > 0 THEN COALESCE(total_ws, total) * (total - refunded) / total ELSE 0 END), 0) AS spend')
            ->first();
        $points = DB::table('loyalty_ledger')->where('workspace_id', app(CurrentWorkspace::class)->id())->where('contact_id', $contactId)
            ->where('kind', 'points')->where('status', 'confirmed')->where('delta', '>', 0)->whereNotIn('reason', ['merge', 'redeem_undo'])
            ->where('created_at', '>=', $since)->sum('delta');

        return [
            'spend_12m' => round((float) ($orders->spend ?? 0), 2),
            'points_12m' => (float) $points,
            'orders_12m' => (float) ($orders->n ?? 0),
        ];
    }

    /** @return list<array{0: string, 1: string}> sitio y customer_ref («wc:77») de cada cuenta del contacto */
    public static function customerRefs(string $contactId): array
    {
        $out = [];
        foreach (ContactIdentity::query()->where('contact_id', $contactId)->where('type', 'customer_ref')->pluck('value') as $value) {
            $parts = explode(':', (string) $value, 2);
            if (count($parts) === 2 && $parts[1] !== '') {
                $out[] = [$parts[0], $parts[1]];
            }
        }

        return $out;
    }
}
