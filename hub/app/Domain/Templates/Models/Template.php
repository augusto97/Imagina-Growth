<?php

declare(strict_types=1);

namespace App\Domain\Templates\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de un canal (modelo-de-datos.md §5, modulos.md M08). Correo: MJML
 * del editor (`body_mjml`), HTML compilado en el navegador (`body_html`) y el
 * proyecto de GrapesJS (`editor_state`). SMS: `body_text`. WhatsApp: apunta a la
 * plantilla aprobada (`wa_template_id`, de wa_templates) y `variables` dice qué
 * expresión Liquid llena cada parámetro.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $channel
 * @property string $name
 * @property string $category
 * @property string|null $subject
 * @property string|null $preheader
 * @property string|null $body_mjml
 * @property string|null $body_html
 * @property string|null $body_text
 * @property bool $sms_allow_multipart SMS de hasta 2 partes (cuestan el doble); por defecto, uno solo
 * @property string|null $editor_state
 * @property string|null $wa_template_id
 * @property array<string, mixed>|null $variables
 * @property string|null $library_key
 * @property string $status
 * @property string|null $created_by
 */
class Template extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'tpl';

    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    public const CATEGORIES = ['marketing', 'utility', 'transactional'];

    public const STATUSES = ['draft', 'ready', 'archived'];

    protected $fillable = ['workspace_id', 'channel', 'name', 'category', 'subject', 'preheader', 'body_mjml', 'body_html', 'body_text', 'sms_allow_multipart', 'editor_state', 'wa_template_id', 'variables', 'library_key', 'status', 'created_by'];

    protected $attributes = ['category' => 'marketing', 'status' => 'draft', 'sms_allow_multipart' => false];

    protected function casts(): array
    {
        return ['variables' => 'array', 'sms_allow_multipart' => 'boolean'];
    }

    public function isMarketing(): bool
    {
        return $this->category === 'marketing';
    }
}
