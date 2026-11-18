<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Entrada del libro de puntos y saldo (`loyalty_ledger`). **Solo inserción**:
 * confirmar, revertir o vencer agrega otra entrada enlazada por `parent_id`
 * (ver DatabaseLoyaltyLedger para el significado de cada estado).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $contact_id
 * @property string $kind
 * @property string $delta
 * @property string $balance_after
 * @property string|null $currency
 * @property string $status
 * @property string $reason
 * @property string|null $ref_type
 * @property string|null $ref_id
 * @property string|null $parent_id
 * @property string $idempotency_key
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $created_at
 */
class LedgerEntry extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'lg';

    public const UPDATED_AT = null;

    protected $table = 'loyalty_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['delta' => 'decimal:2', 'balance_after' => 'decimal:2', 'expires_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function points(): int
    {
        return (int) round((float) $this->delta);
    }
}
