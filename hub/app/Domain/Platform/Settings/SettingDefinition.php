<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use Closure;

/**
 * Un ajuste de la plataforma (F17): qué es, cómo se valida, qué valores de `config()` alimenta y de qué variable del
 * .env sale cuando el panel no tiene valor. Los textos (nombre, ayuda, dónde se obtiene) viven en la SPA
 * (`platformSettings.fields.<sección>.<nombre>`).
 *
 * - `type`: text | secret | bool | int | number | enum | email | url. Un `secret` se guarda cifrado y nunca se devuelve.
 * - `targets`: claves de `config()` que recibe el valor (todas el mismo, salvo que `toConfig` diga otra cosa).
 * - `toConfig`: valor del panel → [clave de config => valor], para los que no se copian tal cual.
 * - `fromConfig`: [clave de config => valor del .env] → el valor en la forma del ajuste (para mostrar el respaldo).
 * - `generate`: `token` (la SPA arma uno al azar para que la persona lo copie antes de guardar) o `vapid` (el hub crea
 *   el par de llaves y guarda la privada sin mostrarla).
 */
final readonly class SettingDefinition
{
    /**
     * @param  list<string>  $targets
     * @param  list<mixed>  $rules  reglas de Laravel además de las del tipo
     * @param  list<string>  $options  valores posibles de un `enum`
     * @param  (Closure(mixed): array<string, mixed>)|null  $toConfig
     * @param  (Closure(array<string, mixed>): mixed)|null  $fromConfig
     */
    public function __construct(
        public string $key,
        public string $type,
        public array $targets,
        public ?string $env = null,
        public array $rules = [],
        public array $options = [],
        public ?string $generate = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?Closure $toConfig = null,
        public ?Closure $fromConfig = null,
    ) {}

    public function section(): string
    {
        return explode('.', $this->key, 2)[0];
    }

    public function name(): string
    {
        return explode('.', $this->key, 2)[1] ?? $this->key;
    }

    public function secret(): bool
    {
        return $this->type === 'secret';
    }

    /**
     * Lo que el valor del panel escribe en config().
     *
     * @return array<string, mixed>
     */
    public function configFor(mixed $value): array
    {
        if ($this->toConfig !== null) {
            return ($this->toConfig)($value);
        }

        return array_fill_keys($this->targets, $value);
    }

    /**
     * El valor del ajuste según lo que dice config() sin el panel (el .env o el valor por defecto).
     *
     * @param  array<string, mixed>  $baseline
     */
    public function fromBaseline(array $baseline): mixed
    {
        if ($this->fromConfig !== null) {
            return ($this->fromConfig)($baseline);
        }

        return $baseline[$this->targets[0]] ?? null;
    }

    /** Convierte lo que llega de la SPA (ya validado) al tipo del ajuste. null = vacío. */
    public function cast(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($this->type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            'int' => (int) $value,
            'number' => (float) $value,
            'email' => mb_strtolower(trim((string) $value)),
            'secret' => (string) $value,
            default => trim((string) $value),
        };
    }

    /** Si el valor efectivo cuenta como «configurado». */
    public static function filled(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
    }
}
