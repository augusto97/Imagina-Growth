<?php

declare(strict_types=1);

namespace App\Domain\Capture\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * De dónde llegan los leads de anuncios (modulos.md M18 «Leads de anuncios»): una página de Facebook suscrita a
 * `leadgen` (Meta Lead Ads, con el token de la página) o el webhook de los formularios de clientes potenciales de
 * Google Ads (uno por espacio de trabajo, con el token de su URL y la clave que manda Google). `secret` va cifrado y
 * nunca sale en la API. `settings.forms` dice, por formulario, qué casilla o pregunta es el consentimiento de cada
 * canal (sin eso el lead entra sin consentimiento de marketing) y cuál es el servicio.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $platform
 * @property string|null $external_id
 * @property string|null $name
 * @property string|null $token
 * @property string|null $secret
 * @property string $status
 * @property string|null $last_error
 * @property array<string, mixed>|null $settings
 * @property string|null $oauth_connection_id
 * @property CarbonInterface|null $last_lead_at
 * @property CarbonInterface|null $test_received_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LeadAdSource extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'las';

    public const META = 'meta';

    public const GOOGLE = 'google';

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'settings' => 'array',
            'last_lead_at' => 'datetime',
            'test_received_at' => 'datetime',
        ];
    }

    /**
     * Cómo leer un formulario: `consent` [{key, channel, text?}] y `service_key`.
     *
     * @return array{name?: string|null, consent: list<array{key: string, channel: string, text?: string|null}>, service_key: string|null}
     */
    public function form(?string $formId): array
    {
        $forms = (array) (($this->settings ?? [])['forms'] ?? []);
        $form = $formId !== null && is_array($forms[$formId] ?? null) ? $forms[$formId] : [];
        $consent = [];
        foreach ((array) ($form['consent'] ?? []) as $rule) {
            if (is_array($rule) && is_string($rule['key'] ?? null) && is_string($rule['channel'] ?? null)) {
                $consent[] = ['key' => $rule['key'], 'channel' => $rule['channel'], 'text' => is_string($rule['text'] ?? null) ? $rule['text'] : null];
            }
        }

        return [
            'name' => is_string($form['name'] ?? null) ? $form['name'] : null,
            'consent' => $consent,
            'service_key' => is_string($form['service_key'] ?? null) ? $form['service_key'] : null,
        ];
    }
}
