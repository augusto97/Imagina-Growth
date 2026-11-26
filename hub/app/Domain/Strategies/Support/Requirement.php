<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Support;

/**
 * Un requisito verificable de una estrategia (docs/diseno/rediseno-v2.md §3 «bloqueos que enseñan»): qué hace falta,
 * si ya está, si es obligatorio para activarla y adónde ir a resolverlo (ruta de la SPA relativa a /w/:ws).
 * Los textos salen de `lang/es/strategies.php` → `requirements.<key>`.
 */
final class Requirement
{
    /** @param array<string, string|int> $params marcadores de los textos */
    public function __construct(
        public readonly string $key,
        public readonly bool $met,
        public readonly bool $required = true,
        public readonly ?string $to = null,
        public readonly array $params = [],
    ) {}

    public function blocks(): bool
    {
        return $this->required && ! $this->met;
    }

    /** @return array{key: string, met: bool, required: bool, label: string, hint: string|null, action: string|null, to: string|null} */
    public function toArray(): array
    {
        $base = 'strategies.requirements.'.$this->key;

        return [
            'key' => $this->key,
            'met' => $this->met,
            'required' => $this->required,
            'label' => self::text($base.'.label', $this->params) ?? $this->key,
            'hint' => $this->met ? null : self::text($base.($this->required ? '.missing' : '.optional'), $this->params) ?? self::text($base.'.missing', $this->params),
            'action' => $this->met ? null : self::text($base.'.action', $this->params),
            'to' => $this->met ? null : $this->to,
        ];
    }

    /** @param array<string, string|int> $params */
    private static function text(string $key, array $params): ?string
    {
        $text = __($key, $params);

        return is_string($text) && $text !== $key ? $text : null;
    }
}
