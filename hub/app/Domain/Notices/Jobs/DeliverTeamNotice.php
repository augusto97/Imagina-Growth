<?php

declare(strict_types=1);

namespace App\Domain\Notices\Jobs;

use App\Domain\Notices\Actions\NotifyTeam;
use App\Domain\Notices\Data\TeamNotice;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Entrega un aviso al equipo fuera de la petición (lote 0.4.0): el correo y el navegador no hacen esperar a quien agenda.
 * Cifrado: lleva el nombre del contacto.
 */
final class DeliverTeamNotice implements ShouldBeEncrypted, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param array<string, mixed> $notice */
    public function __construct(public string $workspaceIdValue, public array $notice)
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

    public function handle(NotifyTeam $notify): void
    {
        $workspace = Workspace::query()->find($this->workspaceIdValue);
        if ($workspace !== null) {
            $notify->handle($workspace, TeamNotice::fromArray($this->notice));
        }
    }

    public static function for(Workspace $workspace, TeamNotice $notice): void
    {
        self::dispatch($workspace->id, $notice->toArray())->afterCommit();
    }
}
