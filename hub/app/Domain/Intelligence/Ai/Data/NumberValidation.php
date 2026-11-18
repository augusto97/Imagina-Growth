<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Data;

final class NumberValidation
{
    /**
     * @param  list<string>  $checked  las cifras del texto que se revisaron (tal como aparecen)
     * @param  list<string>  $unknown  las que no están en los hechos
     */
    public function __construct(
        public readonly array $checked,
        public readonly array $unknown,
    ) {}

    public function ok(): bool
    {
        return $this->unknown === [];
    }

    /** @return array{ok: bool, checked: int, unknown: list<string>} */
    public function toArray(): array
    {
        return ['ok' => $this->ok(), 'checked' => count($this->checked), 'unknown' => $this->unknown];
    }
}
