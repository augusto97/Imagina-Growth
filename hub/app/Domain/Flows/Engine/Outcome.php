<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine;

use Carbon\CarbonImmutable;

/**
 * Resultado de un nodo (hub/CLAUDE.md §7): Continue(rama), Wait(until),
 * WaitForEvent(evento, timeout), Deferred(until) o Exit. `record` es lo que
 * queda en flow_run_steps.outcome y `details` lo que se guarda con el paso.
 */
final class Outcome
{
    public const CONTINUE = 'continue';

    public const WAIT = 'wait';

    public const WAIT_EVENT = 'wait_event';

    public const DEFERRED = 'deferred';

    public const EXIT = 'exit';

    /** Resultados de paso ya cerrados: un reintento no repite el nodo, sigue por su rama. */
    public const DONE = ['continue', 'sent', 'skipped_holdout', 'denied', 'failed', 'exit'];

    /** @param array<string, mixed> $details */
    private function __construct(
        public readonly string $kind,
        public readonly string $record,
        public readonly ?string $branch = null,
        public readonly bool $fallback = false,
        public readonly ?CarbonImmutable $until = null,
        public readonly ?string $event = null,
        public readonly array $details = [],
    ) {}

    /**
     * Sigue por `branch` (null = salida normal). Con `fallback`, si el grafo no
     * tiene esa rama sigue por la salida normal.
     *
     * @param  array<string, mixed>  $details
     */
    public static function next(?string $branch = null, string $record = 'continue', array $details = [], bool $fallback = false): self
    {
        return new self(self::CONTINUE, $record, $branch, $fallback, details: $details);
    }

    /** @param array<string, mixed> $details */
    public static function wait(CarbonImmutable $until, array $details = []): self
    {
        return new self(self::WAIT, 'wait', until: $until->utc(), details: ['until' => $until->utc()->toIso8601String()] + $details);
    }

    /** @param array<string, mixed> $details */
    public static function waitForEvent(string $event, CarbonImmutable $timeout, array $details = []): self
    {
        return new self(self::WAIT_EVENT, 'wait_event', until: $timeout->utc(), event: $event, details: ['event' => $event, 'timeout_at' => $timeout->utc()->toIso8601String()] + $details);
    }

    /** @param array<string, mixed> $details */
    public static function deferred(CarbonImmutable $until, string $reason, array $details = []): self
    {
        return new self(self::DEFERRED, 'deferred', until: $until->utc(), details: ['until' => $until->utc()->toIso8601String(), 'reason' => $reason] + $details);
    }

    public static function exit(string $reason = 'exit_node'): self
    {
        return new self(self::EXIT, 'exit', details: ['reason' => $reason]);
    }
}
