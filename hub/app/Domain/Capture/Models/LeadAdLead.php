<?php

declare(strict_types=1);

namespace App\Domain\Capture\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Un lead de un formulario de anuncios (Meta Lead Ads o Google Ads), idempotente por (espacio de trabajo, plataforma,
 * ID del lead): el mismo aviso dos veces no crea dos contactos ni dos leads. `data` (cifrado) guarda lo que respondió
 * la persona y los datos del anuncio; los de prueba (`is_test`) quedan marcados y no crean contacto ni lead.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $source_id
 * @property string $platform
 * @property string $external_id
 * @property string|null $form_id
 * @property string|null $campaign_id
 * @property string|null $adset_id
 * @property string|null $ad_id
 * @property bool $is_test
 * @property string $status
 * @property string|null $error
 * @property int $attempts
 * @property string|null $contact_id
 * @property string|null $lead_id
 * @property array<string, mixed>|null $data
 * @property list<string>|null $consents
 * @property CarbonInterface $received_at
 * @property CarbonInterface|null $processed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LeadAdLead extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'lal';

    protected $guarded = [];

    protected $attributes = ['status' => 'received', 'attempts' => 0, 'is_test' => false];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'data' => 'encrypted:array',
            'consents' => 'array',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
