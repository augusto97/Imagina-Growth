<?php

declare(strict_types=1);

namespace App\Domain\Consent\Policy;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Lo que SendPolicy necesita para decidir. `contact` es null en los avisos al
 * equipo (van a `to`); `isTest` es el modo prueba de un flujo o una campaña.
 */
final class PolicyRequest
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly ?Contact $contact,
        public readonly Channel $channel,
        public readonly MessageKind $kind,
        public readonly string $sourceType = 'flow',
        public readonly ?ChannelAccount $account = null,
        public readonly ?CarbonImmutable $at = null,
        public readonly bool $isTest = false,
        public readonly bool $hasExplicitRecipient = false,
    ) {}

    public function at(): CarbonImmutable
    {
        return ($this->at ?? CarbonImmutable::now())->utc();
    }
}
