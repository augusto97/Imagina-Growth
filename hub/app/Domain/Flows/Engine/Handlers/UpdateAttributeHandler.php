<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;

/** Cambia un atributo personalizado del contacto (`custom_attributes`). */
final class UpdateAttributeHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        $contact = $state->contact;
        if ($contact === null || $state->isTest()) {
            return Outcome::next(details: ['skipped' => true]);
        }
        $key = (string) ($node['config']['key'] ?? '');
        $value = $node['config']['value'] ?? null;
        $attributes = (array) ($contact->getAttribute('custom_attributes') ?? []);
        if ($value === null || $value === '') {
            unset($attributes[$key]);
        } else {
            $attributes[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
        }
        $contact->forceFill(['custom_attributes' => $attributes])->save();

        return Outcome::next(details: ['key' => $key]);
    }
}
