<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Platforms;

use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Conversions\DeliveryResult;
use App\Domain\Measurement\Conversions\ErrorTranslator;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Measurement\Support\UserDataHasher as H;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Audiencias de TikTok con la Segment API de TikTok for Business:
 * `segment/audience/` crea la audiencia y `segment/mapping/` agrega o quita
 * identificadores (EMAIL_SHA256 y PHONE_SHA256, E.164 con «+»), por lotes.
 * TikTok responde 200 con un `code` distinto de 0 cuando falla.
 * [verificar] con una cuenta real: rutas, nombres de campos y límites por lote.
 */
final class TikTokAudiences implements AudiencePlatform
{
    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function missing(AdDestination $destination): ?string
    {
        if ($destination->credential('advertiser_id') === null || $this->token($destination) === null) {
            return __('Para audiencias de TikTok falta el ID del anunciante y el token de la API de marketing en el destino.');
        }

        return null;
    }

    public function create(AdDestination $destination, AudienceSync $sync): array
    {
        try {
            $response = Http::timeout(20)->withHeaders(['Access-Token' => (string) $this->token($destination)])->asJson()
                ->post($this->url('segment/audience/'), [
                    'advertiser_id' => (string) $destination->credential('advertiser_id'),
                    'custom_audience_name' => mb_substr($sync->name, 0, 128),
                    'action' => 'create',
                ]);
        } catch (ConnectionException $e) {
            return [ErrorTranslator::connection('TikTok', $e->getMessage()), null];
        }
        $id = $response->json('data.audience_id');
        if ($response->successful() && (int) $response->json('code', -1) === 0 && (is_string($id) || is_int($id))) {
            return [DeliveryResult::delivered($response->status()), (string) $id];
        }

        return [ErrorTranslator::tiktok($response), null];
    }

    public function add(AdDestination $destination, string $audienceId, array $members): DeliveryResult
    {
        return $this->mapping('add', $destination, $audienceId, $members);
    }

    public function remove(AdDestination $destination, string $audienceId, array $members): DeliveryResult
    {
        return $this->mapping('delete', $destination, $audienceId, $members);
    }

    public function identifiers(?string $email, ?string $phone, string $defaultCountry): array
    {
        return array_filter([
            'email' => H::hash(H::email($email)),
            'phone' => H::hash(H::phone($phone, $defaultCountry, Platform::TikTok)),
        ], fn ($v) => $v !== null);
    }

    /** @return positive-int */
    public function batchSize(): int
    {
        return max(1, (int) config('igr-audiences.batch_size.tiktok', 5000));
    }

    /** @param list<array<string, string>> $members */
    private function mapping(string $action, AdDestination $destination, string $audienceId, array $members): DeliveryResult
    {
        if ($members === []) {
            return DeliveryResult::delivered();
        }
        // Cada fila sigue el orden de id_schema; una clave que la persona no tiene va vacía.
        $batch = array_map(fn (array $m) => [
            ['id' => $m['email'] ?? '', 'audience_ids' => [$audienceId]],
            ['id' => $m['phone'] ?? '', 'audience_ids' => [$audienceId]],
        ], $members);
        try {
            $response = Http::timeout(60)->withHeaders(['Access-Token' => (string) $this->token($destination)])->asJson()
                ->post($this->url('segment/mapping/'), [
                    'advertiser_ids' => [(string) $destination->credential('advertiser_id')],
                    'action' => $action,
                    'id_schema' => ['EMAIL_SHA256', 'PHONE_SHA256'],
                    'batch_data' => $batch,
                ]);
        } catch (ConnectionException $e) {
            return ErrorTranslator::connection('TikTok', $e->getMessage());
        }

        return $response->successful() && (int) $response->json('code', -1) === 0 ? DeliveryResult::delivered($response->status()) : ErrorTranslator::tiktok($response);
    }

    private function token(AdDestination $destination): ?string
    {
        return $destination->credential('ads_access_token') ?? $destination->credential('access_token');
    }

    private function url(string $path): string
    {
        return rtrim((string) config('igr-audiences.tiktok.api_url'), '/').'/'.$path;
    }
}
