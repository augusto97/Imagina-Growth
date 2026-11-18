<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Support\HeuristicInboundClassifier;
use App\Domain\Inbox\Support\NullConversationAgent;
use Illuminate\Support\ServiceProvider;

/** M11 Bandeja: contratos por defecto (el agente de IA y el clasificador de IA los reemplazan). */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ConversationAgent::class, NullConversationAgent::class);
        $this->app->bindIf(InboundClassifier::class, HeuristicInboundClassifier::class);
    }
}
