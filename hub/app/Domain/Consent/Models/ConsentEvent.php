<?php

declare(strict_types=1);

namespace App\Domain\Consent\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Prueba de cada autorización o revocación (cumplimiento.md §2). Solo inserción.
 *
 * @property int $id
 * @property string $contact_id
 * @property string $channel
 * @property string $action
 * @property array<string, mixed>|null $evidence
 */
class ConsentEvent extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'occurred_at' => 'datetime'];
    }

    public static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
