<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Support;

use App\Domain\Intelligence\Insights\Models\InsightAssumption;
use App\Support\Tenancy\CurrentWorkspace;
use InvalidArgumentException;

/**
 * Los supuestos del impacto del espacio de trabajo activo: el valor que puso la
 * comerciante o el de `config/igr-insights.php`.
 */
final class Assumptions
{
    /** @var array<string, array<string, float>> por espacio de trabajo */
    private array $overrides = [];

    public function __construct(private readonly CurrentWorkspace $current) {}

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys((array) config('igr-insights.assumptions', []));
    }

    public static function default(string $key): float
    {
        $def = config("igr-insights.assumptions.{$key}");
        if (! is_array($def)) {
            throw new InvalidArgumentException("Supuesto desconocido: {$key}");
        }

        return (float) $def['default'];
    }

    public function value(string $key): float
    {
        $ws = $this->current->id();
        $this->overrides[$ws] ??= InsightAssumption::query()->pluck('value', 'key')->map(fn ($v) => (float) $v)->all();

        return $this->overrides[$ws][$key] ?? self::default($key);
    }

    /** @return array<string, float> todos los valores vigentes */
    public function all(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = $this->value($key);
        }

        return $out;
    }

    /**
     * @return list<array{key: string, value: float, default: float, unit: string, min: float, max: float, edited: bool}>
     */
    public function describe(): array
    {
        $out = [];
        foreach ((array) config('igr-insights.assumptions', []) as $key => $def) {
            $value = $this->value((string) $key);
            $out[] = ['key' => (string) $key, 'value' => $value, 'default' => (float) $def['default'], 'unit' => (string) $def['unit'],
                'min' => (float) $def['min'], 'max' => (float) $def['max'], 'edited' => abs($value - (float) $def['default']) > 1e-9];
        }

        return $out;
    }

    public function forget(): void
    {
        $this->overrides = [];
    }
}
