<?php

declare(strict_types=1);

namespace App\Domain\Capture\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Chat de WhatsApp con atribución (rediseño v2 §11.3): un enlace corto del hub (`/p/w/{código}`) que cuenta el
 * escaneo o el clic y abre wa.me con el número conectado y el mensaje de alta, que termina en «(ref: CÓDIGO)», el
 * mismo formato del widget de WhatsApp (eventos.md §6.3). Cuando el mensaje llega, el chat queda atribuido al QR
 * impreso (`kind = qr`, UTM `qr`) o a la página de captura (`kind = page`), con su lead y, si se pidió, la pregunta
 * de consentimiento con botones (cumplimiento.md §3.1).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $page_id
 * @property string|null $channel_account_id
 * @property string $kind
 * @property string $name
 * @property string $code
 * @property string $message
 * @property string $status
 * @property array<string, mixed>|null $settings
 * @property string|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class WhatsAppLink extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'wl';

    public const KINDS = ['qr', 'page'];

    protected $table = 'whatsapp_links';

    protected $guarded = [];

    protected $attributes = ['status' => 'active', 'kind' => 'qr'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    /** ¿Crea el lead cuando llega el chat? Por defecto sí. */
    public function createsLead(): bool
    {
        return (bool) $this->setting('create_lead', true);
    }

    /** ¿Pregunta con botones si quiere recibir novedades por WhatsApp? Por defecto sí. */
    public function asksConsent(): bool
    {
        return (bool) $this->setting('ask_consent', true);
    }
}
