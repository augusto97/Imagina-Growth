<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan de la plataforma (M21). La clave es legible (`tienda_crece`); los datos vienen
 * de `config/igr-plans.php` con `igr:plans:sync`. No pertenece a un espacio de trabajo.
 *
 * @property string $id
 * @property string $name
 * @property string $metric orders | contacts
 * @property int $included
 * @property list<string> $modules
 * @property array<string, int|float>|null $limits
 * @property string $price_usd
 * @property string|null $price_cop
 * @property bool $public
 */
class Plan extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'metric', 'included', 'modules', 'limits', 'price_usd', 'price_cop', 'public'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'limits' => 'array', 'included' => 'integer', 'public' => 'boolean', 'price_usd' => 'decimal:2', 'price_cop' => 'decimal:2'];
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'metric' => $this->metric, 'included' => $this->included,
            'modules' => array_values((array) $this->modules), 'limits' => (array) ($this->limits ?? []),
            'price_usd' => (string) $this->price_usd, 'price_cop' => $this->price_cop !== null ? (string) $this->price_cop : null,
        ];
    }
}
