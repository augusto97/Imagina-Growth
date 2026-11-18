<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Push;

/** Doble para las pruebas: guarda cada notificación. */
final class FakePushNotifier implements PushNotifier
{
    /** @var list<array{workspace: string, user: string, title: string, url: string}> */
    public array $sent = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(PushNotifier::class, $fake);

        return $fake;
    }

    public function notify(string $workspaceId, string $userId, string $title, string $url): int
    {
        $this->sent[] = ['workspace' => $workspaceId, 'user' => $userId, 'title' => $title, 'url' => $url];

        return 1;
    }
}
