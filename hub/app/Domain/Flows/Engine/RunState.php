<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Graph\Graph;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowRunStep;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/** Lo que un nodo necesita saber: el run, su flujo, el contacto, el intento y el paso previo (si es un reintento o una reanudación). */
final class RunState
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly Flow $flow,
        public readonly FlowRun $run,
        public readonly Graph $graph,
        public readonly ?Contact $contact,
        public readonly int $attempt,
        public readonly ?FlowRunStep $step,
        public readonly CarbonImmutable $now,
    ) {}

    public function isHoldout(): bool
    {
        return $this->run->is_holdout;
    }

    public function isTest(): bool
    {
        return $this->run->isTest();
    }

    /** Clave de idempotencia del nodo en esta visita (MessageDispatcher). */
    public function idempotencyKey(string $nodeId, ?string $suffix = null): string
    {
        return 'run:'.$this->run->id.':'.$nodeId.':'.$this->attempt.($suffix !== null ? ':'.$suffix : '');
    }

    /** @return array<string, mixed> */
    public function stepDetails(): array
    {
        return (array) ($this->step?->details ?? []);
    }

    /** Guarda un dato en el contexto del run (p. ej. el cupón generado). */
    public function remember(string $key, mixed $value): void
    {
        $context = (array) ($this->run->context ?? []);
        $context[$key] = $value;
        $this->run->context = $context;
    }
}
