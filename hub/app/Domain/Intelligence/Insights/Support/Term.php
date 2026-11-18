<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Support;

/**
 * Un factor de la fórmula del impacto: «37 clientes», «18 % de reactivación de
 * referencia», «$185.000 de ticket». Si viene de un supuesto editable, lleva su
 * clave (`assumption`) y se recalcula cuando la comerciante lo cambia.
 */
final class Term
{
    public const UNITS = ['count', 'rate', 'money', 'days', 'number'];

    /** @param 'count'|'rate'|'money'|'days'|'number' $unit */
    public function __construct(
        public readonly string $label,
        public readonly float $value,
        public readonly string $unit,
        public readonly ?string $assumption = null,
    ) {}

    /** @return array{label: string, value: float, unit: string, assumption: string|null} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'value' => round($this->value, 6), 'unit' => $this->unit, 'assumption' => $this->assumption];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $unit = (string) ($row['unit'] ?? 'number');

        return new self((string) ($row['label'] ?? ''), (float) ($row['value'] ?? 0), in_array($unit, self::UNITS, true) ? $unit : 'number',
            isset($row['assumption']) ? (string) $row['assumption'] : null);
    }
}
