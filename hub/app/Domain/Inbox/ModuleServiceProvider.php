<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Inbox\Listeners\OnInboundMessage;
use App\Domain\Inbox\Push\LogPushNotifier;
use App\Domain\Inbox\Push\PushNotifier;
use App\Domain\Inbox\Support\NullConversationAgent;
use App\Domain\Intelligence\Ai\Features\Classifiers\AiInboundClassifier;
use App\Domain\Intelligence\Ai\Features\Classifiers\ClassifyInboundMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** M11 Bandeja: contratos por defecto (el agente de IA y el clasificador de IA los reemplazan) y el oyente de entrantes. */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ConversationAgent::class, NullConversationAgent::class);
        // Fase 4 (fork N): con IA disponible clasifica Claude Haiku; si no, las palabras clave.
        $this->app->bindIf(InboundClassifier::class, AiInboundClassifier::class);
        $this->app->bindIf(PushNotifier::class, LogPushNotifier::class);
    }

    public function boot(): void
    {
        Event::listen(InboundMessageReceived::class, OnInboundMessage::class);
        Event::listen(InboundMessageReceived::class, ClassifyInboundMessage::class);
    }
}
