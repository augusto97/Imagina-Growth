<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Contracts;

use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;

/**
 * Todo envío pasa por aquí (hub/CLAUDE.md §8): cuenta del canal → SendPolicy →
 * plantilla (TemplateRenderer) → enlaces rastreados → `messages` → cola
 * `messages:{canal}` con límite por cuenta → driver. Debe correr dentro del
 * contexto del espacio de trabajo (CurrentWorkspace).
 */
interface MessageDispatcher
{
    public function dispatch(OutboundMessage $message): DispatchResult;
}
