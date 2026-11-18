<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Push;

/**
 * Notificaciones push de la PWA de la bandeja (M11). El cuerpo nunca lleva el
 * texto del mensaje ni datos del contacto: solo «Nuevo mensaje» y el enlace a la
 * conversación. `LogPushNotifier` mientras no haya llaves VAPID y una librería de
 * Web Push instalada [verificar].
 */
interface PushNotifier
{
    /** @return int suscripciones a las que se envió */
    public function notify(string $workspaceId, string $userId, string $title, string $url): int;
}
