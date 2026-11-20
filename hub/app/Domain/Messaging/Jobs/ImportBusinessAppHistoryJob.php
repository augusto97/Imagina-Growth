<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Domain\Messaging\Channels\Data\BusinessAppMessage;
use App\Domain\Messaging\Coexistence\ImportBusinessAppHistory;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Una parte del historial de la app de WhatsApp Business (coexistencia), fuera del webhook: puede traer cientos de
 * mensajes. Lleva los textos, así que va cifrada en la cola (S15). Repetirla no duplica nada.
 */
final class ImportBusinessAppHistoryJob implements ShouldBeEncrypted, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    /** @param array{phase: int|null, chunk: int|null, progress: int|null, declined: bool, messages: list<BusinessAppMessage>} $chunk */
    public function __construct(public string $workspaceIdValue, public string $accountId, public array $chunk)
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

    public function handle(ImportBusinessAppHistory $import): void
    {
        $account = ChannelAccount::query()->find($this->accountId);
        if ($account !== null && $account->isActive()) {
            $import->handle($account, $this->chunk);
        }
    }
}
