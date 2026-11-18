<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;

/** Agrega o quita etiquetas del contacto. */
final class TagHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        $contact = $state->contact;
        if ($contact === null || $state->isTest()) {
            return Outcome::next(details: ['skipped' => true]);
        }
        $tags = array_values(array_map('strval', (array) ($contact->getAttribute('tags') ?? [])));
        $add = array_map(fn ($t) => mb_substr(trim((string) $t), 0, 40), (array) ($node['config']['add'] ?? []));
        $remove = array_map(fn ($t) => trim((string) $t), (array) ($node['config']['remove'] ?? []));
        $next = array_values(array_unique(array_diff([...$tags, ...array_filter($add)], $remove)));
        $contact->forceFill(['tags' => $next])->save();

        return Outcome::next(details: ['added' => $add, 'removed' => $remove]);
    }
}
