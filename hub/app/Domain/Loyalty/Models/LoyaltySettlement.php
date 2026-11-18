<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuándo se confirman los puntos pendientes de una referencia (un pedido): el
 * job `igr:loyalty:settle` los confirma o revierte al llegar `due_at`.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $ref_type
 * @property string $ref_id
 * @property CarbonInterface $due_at
 * @property string $status
 */
class LoyaltySettlement extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'lst';

    protected $fillable = ['workspace_id', 'ref_type', 'ref_id', 'due_at', 'status'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }
}
