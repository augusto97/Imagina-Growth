<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

/**
 * Cómo está un bloque: `on` (funcionando), `paused` (existe y se detuvo) u `off` (no existe o nunca se prendió), con
 * los problemas que tiene si está prendido (`channel_down`, `template_rejected`, `site_down`, `ai_unavailable`,
 * `destination_error`…) y adónde ir a ajustarlo.
 */
final class BlockState
{
    public const ON = 'on';

    public const PAUSED = 'paused';

    public const OFF = 'off';

    /**
     * @param  list<string>  $problems
     * @param  array<string, list<string>>  $refs  IDs internos de lo que lo forma (`flows`, `widgets`…)
     */
    public function __construct(
        public readonly string $status,
        public readonly array $problems = [],
        public readonly ?string $to = null,
        public readonly array $refs = [],
    ) {}

    /**
     * @param  list<string>  $problems
     * @param  array<string, list<string>>  $refs
     */
    public static function on(?string $to = null, array $problems = [], array $refs = []): self
    {
        return new self(self::ON, array_values($problems), $to, $refs);
    }

    /** @param array<string, list<string>> $refs */
    public static function paused(?string $to = null, array $refs = []): self
    {
        return new self(self::PAUSED, [], $to, $refs);
    }

    /** @param array<string, list<string>> $refs */
    public static function off(?string $to = null, array $refs = []): self
    {
        return new self(self::OFF, [], $to, $refs);
    }

    public function isOn(): bool
    {
        return $this->status === self::ON;
    }
}
