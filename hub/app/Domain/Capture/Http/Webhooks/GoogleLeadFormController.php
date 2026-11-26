<?php

declare(strict_types=1);

namespace App\Domain\Capture\Http\Webhooks;

use App\Domain\Capture\LeadAds\ReceiveAdLead;
use App\Domain\Capture\Models\LeadAdSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `POST /webhooks/google/lead-forms/{token}`: el webhook de los formularios de clientes potenciales de Google Ads
 * (https://developers.google.com/google-ads/webhook/docs/implementation). El token de la URL dice de qué espacio de
 * trabajo es; Google manda en el cuerpo la clave que se generó en el panel (`google_key`), que se compara cifrada y
 * en tiempo constante. Idempotente por `lead_id`; los de prueba (`is_test`) se guardan marcados y no crean contacto ni
 * lead. Responde 200 en cuanto lo guarda: el lead se procesa en la cola. Sin sesión ni CSRF (routes/public).
 */
final class GoogleLeadFormController
{
    public function __invoke(Request $request, string $token, ReceiveAdLead $receive): JsonResponse
    {
        if (strlen($request->getContent()) > (int) config('igr-capture.google_max_body_kb', 64) * 1024) {
            return response()->json(['error' => 'too_large'], 413);
        }
        $limit = 'igr-capture:google:'.$token;
        if (RateLimiter::tooManyAttempts($limit, 600)) {
            return response()->json(['error' => 'too_many_requests'], 429);
        }
        RateLimiter::hit($limit, 60);
        $source = LeadAdSource::withoutWorkspaceScope()->where('platform', LeadAdSource::GOOGLE)->where('token', $token)->first();
        if ($source === null || $source->status === 'disabled') {
            return response()->json(['error' => 'not_found'], 404);
        }
        $payload = json_decode($request->getContent(), true);
        $key = is_array($payload) && is_string($payload['google_key'] ?? null) ? $payload['google_key'] : '';
        $secret = (string) $source->secret;
        if ($secret === '' || ! hash_equals($secret, $key)) {
            Log::warning('igr.capture.google_bad_key', ['source' => $source->id]);

            return response()->json(['error' => 'invalid_key'], 403);
        }
        $leadId = is_scalar($payload['lead_id'] ?? null) ? trim((string) $payload['lead_id']) : '';
        if ($leadId === '' || ! is_array($payload['user_column_data'] ?? null)) {
            return response()->json(['error' => 'invalid_payload'], 422);
        }
        $isTest = in_array($payload['is_test'] ?? false, [true, 1, '1', 'true'], true);
        unset($payload['google_key']);
        $row = $receive->handle($source, $leadId, [
            'form_id' => $payload['form_id'] ?? null, 'campaign_id' => $payload['campaign_id'] ?? null,
            'adset_id' => $payload['adgroup_id'] ?? null, 'ad_id' => $payload['creative_id'] ?? null,
        ], ['payload' => $payload], $isTest);

        return response()->json(['ok' => true, 'duplicate' => $row === null], 200);
    }
}
