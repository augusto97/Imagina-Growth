<?php

declare(strict_types=1);

namespace App\Domain\Notices\Realtime;

use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Llegó un aviso nuevo para una persona (lote 0.4.0): solo el ID; la campana vuelve a pedir la lista por la API.
 * Canal privado `workspace.{ws_…}.notices.{usr_…}` (routes/channels.php: solo esa persona, si sigue en el espacio).
 */
final class NoticeCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'default';

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $userId,
        public readonly string $noticeId,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.'.Workspace::ID_PREFIX.'_'.$this->workspaceId.'.notices.'.User::ID_PREFIX.'_'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notice.created';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['notice' => $this->noticeId];
    }
}
