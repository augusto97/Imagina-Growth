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
 * Meta Custom Audiences (Marketing API): `POST /act_{cuenta}/customaudiences`
 * crea la audiencia (subtype CUSTOM, customer_file_source USER_PROVIDED_ONLY) y
 * `POST|DELETE /{audiencia}/users` agrega o quita miembros con el esquema
 * multiclave [EMAIL, PHONE] en SHA-256, hasta 10.000 por petición.
 * [verificar] con una cuenta real: versión de la API, permisos ads_management y
 * la aceptación de los términos de audiencias personalizadas en la cuenta.
 */
final class MetaAudiences implements AudiencePlatform
{
    public function platform(): Platform
    {
        return Platform::Meta;
    }

    public function missing(AdDestination $destination): ?string
    {
        if ($destination->credential('ad_account_id') === null || $this->token($destination) === null) {
            return __('Para audiencias de Meta falta el ID de la cuenta publicitaria y un token con permiso ads_management en el destino.');
        }

        return null;
    }

    public function create(AdDestination $destination, AudienceSync $sync): array
    {
        $account = 'act_'.preg_replace('/^act_/', '', (string) $destination->credential('ad_account_id'));
        try {
            $response = Http::timeout(20)->withToken((string) $this->token($destination))->asForm()->post($this->url($account.'/customaudiences'), [
                'name' => mb_substr($sync->name, 0, 200),
                'subtype' => 'CUSTOM',
                'description' => __('Segmento sincronizado por Imagina Growth'),
                'customer_file_source' => 'USER_PROVIDED_ONLY',
            ]);
        } catch (ConnectionException $e) {
            return [ErrorTranslator::connection('Meta', $e->getMessage()), null];
        }
        $id = $response->json('id');

        return $response->successful() && is_string($id) ? [DeliveryResult::delivered($response->status()), $id] : [ErrorTranslator::meta($response), null];
    }

    public function add(AdDestination $destination, string $audienceId, array $members): DeliveryResult
    {
        return $this->users('post', $destination, $audienceId, $members);
    }

    public function remove(AdDestination $destination, string $audienceId, array $members): DeliveryResult
    {
        return $this->users('delete', $destination, $audienceId, $members);
    }

    public function identifiers(?string $email, ?string $phone, string $defaultCountry): array
    {
        return array_filter([
            'email' => H::hash(H::email($email)),
            'phone' => H::hash(H::phone($phone, $defaultCountry, Platform::Meta)),
        ], fn ($v) => $v !== null);
    }

    /** @return positive-int */
    public function batchSize(): int
    {
        return max(1, (int) config('igr-audiences.batch_size.meta', 10000));
    }

    /** @param list<array<string, string>> $members */
    private function users(string $method, AdDestination $destination, string $audienceId, array $members): DeliveryResult
    {
        if ($members === []) {
            return DeliveryResult::delivered();
        }
        $payload = [
            'schema' => ['EMAIL', 'PHONE'],
            'data' => array_map(fn (array $m) => [$m['email'] ?? '', $m['phone'] ?? ''], $members),
        ];
        $session = ['session_id' => random_int(1, 2 ** 53), 'batch_seq' => 1, 'last_batch_flag' => true, 'estimated_num_total' => count($members)];
        try {
            $response = Http::timeout(60)->withToken((string) $this->token($destination))->asJson()
                ->send(strtoupper($method), $this->url(rawurlencode($audienceId).'/users'), ['json' => ['payload' => $payload, 'session' => $session]]);
        } catch (ConnectionException $e) {
            return ErrorTranslator::connection('Meta', $e->getMessage());
        }

        return $response->successful() ? DeliveryResult::delivered($response->status()) : ErrorTranslator::meta($response);
    }

    private function token(AdDestination $destination): ?string
    {
        return $destination->credential('ads_access_token') ?? $destination->credential('access_token');
    }

    private function url(string $path): string
    {
        return rtrim((string) config('igr-measurement.meta.graph_url'), '/').'/'.config('igr-measurement.meta.graph_version').'/'.$path;
    }
}
