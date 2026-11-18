<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine;

use App\Domain\Flows\Engine\Handlers\AbSplitHandler;
use App\Domain\Flows\Engine\Handlers\ChannelSplitHandler;
use App\Domain\Flows\Engine\Handlers\ConditionHandler;
use App\Domain\Flows\Engine\Handlers\CouponHandler;
use App\Domain\Flows\Engine\Handlers\ExitHandler;
use App\Domain\Flows\Engine\Handlers\NotifyTeamHandler;
use App\Domain\Flows\Engine\Handlers\SendHandler;
use App\Domain\Flows\Engine\Handlers\TagHandler;
use App\Domain\Flows\Engine\Handlers\UpdateAttributeHandler;
use App\Domain\Flows\Engine\Handlers\WaitEventHandler;
use App\Domain\Flows\Engine\Handlers\WaitHandler;
use App\Domain\Flows\Engine\Handlers\WebhookHandler;
use App\Domain\Flows\Graph\NodeTypes;

/** Manejador de cada tipo de nodo (NodeHandler::handle). */
final class NodeHandlers
{
    /** @var array<string, class-string<NodeHandler>> */
    public const MAP = [
        NodeTypes::WAIT => WaitHandler::class,
        NodeTypes::WAIT_EVENT => WaitEventHandler::class,
        NodeTypes::CONDITION => ConditionHandler::class,
        NodeTypes::AB_SPLIT => AbSplitHandler::class,
        NodeTypes::CHANNEL_SPLIT => ChannelSplitHandler::class,
        NodeTypes::SEND_WHATSAPP => SendHandler::class,
        NodeTypes::SEND_EMAIL => SendHandler::class,
        NodeTypes::SEND_SMS => SendHandler::class,
        NodeTypes::COUPON => CouponHandler::class,
        NodeTypes::TAG => TagHandler::class,
        NodeTypes::UPDATE_ATTRIBUTE => UpdateAttributeHandler::class,
        NodeTypes::NOTIFY_TEAM => NotifyTeamHandler::class,
        NodeTypes::WEBHOOK => WebhookHandler::class,
        NodeTypes::EXIT => ExitHandler::class,
    ];

    public function for(string $type): ?NodeHandler
    {
        $class = self::MAP[$type] ?? null;

        return $class === null ? null : app($class);
    }
}
