<?php

declare(strict_types=1);

namespace App\Domain\Capture\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Un envío de una página de captura: el contacto, el lead, de dónde vino (`source`: canal, UTM, `ref`,
 * identificadores de clic; sin datos personales), si llegó por el QR (`via = qr`), los canales que la persona marcó
 * y lo que escribió (`data`, cifrado). La supresión del contacto lo borra (cumplimiento.md §9).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $page_id
 * @property string|null $contact_id
 * @property string|null $lead_id
 * @property string $via
 * @property array<string, string>|null $source
 * @property array<string, mixed>|null $data
 * @property list<string>|null $consents
 * @property bool $was_known
 * @property CarbonInterface $created_at
 */
class LandingSubmission extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'ls';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => 'array',
            'data' => 'encrypted:array',
            'consents' => 'array',
            'was_known' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
