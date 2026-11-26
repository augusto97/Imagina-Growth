<?php

declare(strict_types=1);

namespace App\Domain\Capture\LeadAds;

use App\Domain\Capture\Models\LeadAdSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Los cambios `leadgen` del webhook de la app de Meta (objeto `page`, el mismo `/webhooks/meta` de WhatsApp y Messenger,
 * ya verificado con la firma de la app en `MetaWebhookController`): cada uno trae el ID del lead, de la página, del
 * formulario, del anuncio y del conjunto. La página dice de qué espacio de trabajo es (una página activa en uno solo);
 * el lead se lee después, en la cola, con el token de esa página. Nunca lleva datos personales: no hay qué ocultar.
 * https://developers.facebook.com/docs/graph-api/webhooks/reference/page/#leadgen
 */
final class MetaLeadgenWebhook
{
    public function __construct(private readonly ReceiveAdLead $receive) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return int cuántos leads nuevos quedaron encolados
     */
    public function handle(array $payload): int
    {
        if (($payload['object'] ?? null) !== 'page') {
            return 0;
        }
        $count = 0;
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) (is_array($entry) ? ($entry['changes'] ?? []) : []) as $change) {
                if (! is_array($change) || ($change['field'] ?? null) !== 'leadgen' || ! is_array($change['value'] ?? null)) {
                    continue;
                }
                $value = $change['value'];
                $leadgenId = is_scalar($value['leadgen_id'] ?? null) ? (string) $value['leadgen_id'] : '';
                $pageId = is_scalar($value['page_id'] ?? null) ? (string) $value['page_id'] : (is_scalar($entry['id'] ?? null) ? (string) $entry['id'] : '');
                if ($leadgenId === '' || $pageId === '' || preg_match('/^\d{1,40}$/', $leadgenId.$pageId) !== 1) {
                    continue;
                }
                $source = LeadAdSource::withoutWorkspaceScope()->where('platform', LeadAdSource::META)->where('external_id', $pageId)
                    ->whereIn('status', ['active', 'error'])->orderBy('created_at')->first();
                if ($source === null) {
                    Log::info('igr.capture.leadgen_unknown_page', ['page' => $pageId]);

                    continue;
                }
                try {
                    $row = $this->receive->handle($source, $leadgenId, [
                        'form_id' => $value['form_id'] ?? null, 'ad_id' => $value['ad_id'] ?? null, 'adset_id' => $value['adgroup_id'] ?? null,
                    ], at: is_numeric($value['created_time'] ?? null) ? CarbonImmutable::createFromTimestamp((int) $value['created_time']) : null);
                    $count += $row !== null ? 1 : 0;
                } catch (Throwable $e) {
                    report($e); // un lead que falla no tumba el resto del aviso
                }
            }
        }

        return $count;
    }
}
