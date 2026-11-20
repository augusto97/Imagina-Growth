<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Domain\Messaging\Coexistence\SyncBusinessAppContacts;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** La libreta de contactos de la app de WhatsApp Business (coexistencia), fuera del webhook y cifrada en la cola. */
final class SyncBusinessAppContactsJob implements ShouldBeEncrypted, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    /** @param list<array{phone: string|null, user_id: string|null, full_name: string|null, first_name: string|null, action: string}> $contacts */
    public function __construct(public string $workspaceIdValue, public string $accountId, public array $contacts)
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
        return [30, 120];
    }

    public function handle(SyncBusinessAppContacts $sync): void
    {
        $account = ChannelAccount::query()->find($this->accountId);
        if ($account !== null && $account->isActive()) {
            $sync->handle($account, $this->contacts);
        }
    }
}
