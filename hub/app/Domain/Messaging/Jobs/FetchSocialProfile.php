<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Identity\IdentityResolver;
use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Channels\Drivers\MetaSocialDriver;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Pide el nombre de un contacto nuevo de Messenger o Instagram a la API de perfil
 * de Meta (M11). Si la app no tiene el permiso, el contacto se queda sin nombre y
 * la bandeja muestra «Cliente de Messenger» o «Cliente de Instagram».
 */
final class FetchSocialProfile implements ShouldBeEncrypted, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(
        public string $workspaceIdValue,
        public string $accountId,
        public string $contactId,
        public string $senderId,
    ) {}

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(DriverRegistry $drivers): void
    {
        $account = ChannelAccount::query()->find($this->accountId);
        $contact = Contact::query()->find($this->contactId);
        if ($account === null || $contact === null || $contact->first_name !== null) {
            return;
        }
        $driver = $drivers->for($account->provider);
        $profile = $driver instanceof MetaSocialDriver ? $driver->profile($account, $this->senderId) : null;
        if ($profile === null || $profile['first_name'] === null) {
            return;
        }
        $contact->first_name = mb_substr($profile['first_name'], 0, 100);
        $contact->last_name = $profile['last_name'] !== null ? mb_substr($profile['last_name'], 0, 100) : null;
        $contact->name_prefix_bidx = IdentityResolver::namePrefixes($contact->workspace_id, $contact->first_name.' '.($contact->last_name ?? ''));
        $contact->save();
    }
}
