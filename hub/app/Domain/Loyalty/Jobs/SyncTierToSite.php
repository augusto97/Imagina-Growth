<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Jobs;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Sites\Actions\SendSiteCommand;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * `customer.set_tier` (api-connector.md §6): el plugin guarda la meta
 * `igr_tier` y, si el nivel tiene rol de WooCommerce, se lo pone (precios VIP).
 * Si el contacto cambió de nivel otra vez antes de que corra, manda el actual.
 */
final class SyncTierToSite implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public string $workspaceIdValue, public string $siteId, public string $customerRef, public string $tierId)
    {
        $this->onQueue('default');
    }

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 7200];
    }

    public function handle(SendSiteCommand $command): void
    {
        $site = Site::query()->find($this->siteId);
        $tier = LoyaltyTier::query()->find($this->tierId);
        if ($site === null || $tier === null || ! $site->isActive()) {
            return;
        }
        // Si el nivel del contacto ya es otro, ese envío lo hace su propio job.
        $current = Contact::query()->join('contact_identities as ci', 'ci.contact_id', '=', 'contacts.id')
            ->where('ci.type', 'customer_ref')->where('ci.value', $site->id.':'.$this->customerRef)
            ->value('contacts.tier_id');
        if ($current !== null && $current !== $tier->id) {
            return;
        }
        try {
            $command->handle($site, 'customer.set_tier', array_filter([
                'customer_ref' => $this->customerRef,
                'tier_key' => $tier->publicId(),
                'tier_name' => $tier->name,
                'role' => $tier->wc_role,
            ], fn ($v) => $v !== null && $v !== ''), timeout: 10);
        } catch (RuntimeException $e) {
            Log::warning('igr.loyalty.set_tier_failed', ['site' => $site->id, 'tier' => $tier->id, 'attempt' => $this->attempts()]);

            throw $e;
        }
    }
}
