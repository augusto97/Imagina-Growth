<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Jobs;

use App\Domain\Measurement\Audiences\RemoveContactFromAudiences;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Saca a un contacto de las audiencias al revocar el consentimiento de anuncios. */
final class RemoveContactFromAudiencesJob implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public string $workspaceIdValue, public string $contactId)
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

    public function handle(RemoveContactFromAudiences $remove): void
    {
        $remove->handle($this->contactId);
    }
}
