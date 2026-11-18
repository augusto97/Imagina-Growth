<?php

declare(strict_types=1);

namespace App\Domain\SalesAgent;

use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Intelligence\Ai\Agent\Console\AgentEvalCommand;
use App\Domain\Intelligence\Ai\Agent\SalesAgent;
use Illuminate\Support\ServiceProvider;

/**
 * Agente de ventas con IA (M11, inteligencia.md §6.7). El código vive en
 * `App\Domain\Intelligence\Ai\Agent`; este proveedor lo registra como el
 * `ConversationAgent` de la bandeja (reemplaza al `NullConversationAgent`).
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConversationAgent::class, SalesAgent::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AgentEvalCommand::class]);
        }
    }
}
