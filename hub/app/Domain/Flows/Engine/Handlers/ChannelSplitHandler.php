<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;

/**
 * «Si tiene WhatsApp con consentimiento, si no correo»: sale por el primer canal
 * que SendPolicy no niega para este contacto (aplazar no cuenta como negar), o
 * por `none`. Revisa sin reservar el cupo de frecuencia.
 */
final class ChannelSplitHandler implements NodeHandler
{
    public function __construct(private readonly SendPolicy $policy) {}

    public function handle(RunState $state, array $node): Outcome
    {
        $channels = array_values(array_map('strval', (array) ($node['config']['channels'] ?? [])));
        if ($state->isTest()) {
            return Outcome::next($channels[0] ?? 'none', details: ['channel' => $channels[0] ?? null, 'test' => true]);
        }
        $kind = MessageKind::tryFrom((string) ($node['config']['kind'] ?? 'marketing')) ?? MessageKind::Marketing;
        $reasons = [];
        foreach ($channels as $name) {
            $channel = Channel::tryFrom($name);
            if ($channel === null) {
                continue;
            }
            $decision = $this->policy->check(new PolicyRequest($state->workspace, $state->contact, $channel, $kind, 'flow', at: $state->now));
            if (! $decision->denied()) {
                return Outcome::next($name, details: ['channel' => $name]);
            }
            $reasons[$name] = $decision->reason;
        }

        return Outcome::next('none', details: ['denied' => $reasons]);
    }
}
