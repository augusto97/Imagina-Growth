<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Push;

use App\Domain\Inbox\Models\PushSubscription;
use Illuminate\Support\Facades\Log;

/** Registra la notificación (sin datos personales) y marca las suscripciones usadas. */
final class LogPushNotifier implements PushNotifier
{
    public function notify(string $workspaceId, string $userId, string $title, string $url): int
    {
        $subs = PushSubscription::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->get();
        if ($subs->isEmpty()) {
            return 0;
        }
        PushSubscription::query()->whereIn('id', $subs->pluck('id'))->update(['last_used_at' => now()]);
        Log::info('igr.inbox.push', ['user' => $userId, 'subscriptions' => $subs->count(), 'url' => $url]);

        return $subs->count();
    }
}
