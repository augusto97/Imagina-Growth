<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $filename
 * @property string|null $path
 * @property array<string, string> $mapping
 * @property string $kind csv o history (consentimiento declarado del historial del plugin)
 * @property string|null $site_id
 * @property array{channels: list<string>, source: string, date: string, legal_basis?: string}|null $consent
 * @property string $status
 * @property array<string, int>|null $totals
 * @property list<array{row: int, message: string}>|null $errors
 * @property CarbonInterface|null $finished_at
 */
class ContactImport extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'imp';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'consent' => 'array', 'totals' => 'array', 'errors' => 'array', 'finished_at' => 'datetime'];
    }
}
