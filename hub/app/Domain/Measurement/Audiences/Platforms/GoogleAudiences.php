<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Platforms;

use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Conversions\DeliveryResult;
use App\Domain\Measurement\Conversions\ErrorTranslator;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Measurement\Support\GoogleAccessToken;
use App\Domain\Measurement\Support\GoogleAdsHeaders;
use App\Domain\Measurement\Support\UserDataHasher as H;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Customer Match. La lista se crea con la API de Google Ads
 * (`customers/{id}/userLists:mutate`, `crmBasedUserList` CONTACT_INFO) y los
 * miembros se suben y se quitan con la **Data Manager API**
 * (`audienceMembers:ingest` y `audienceMembers:remove`), en SHA-256 hexadecimal,
 * con el consentimiento de datos y personalización que exige Google en el EEE
 * (aquí se declara concedido porque solo se suben contactos que lo permiten).
 * [verificar] con una cuenta real: nombres de campos, límites por petición y
 * la aceptación de los términos de Customer Match.
 */
final class GoogleAudiences implements AudiencePlatform
{
    public function __construct(private readonly GoogleAccessToken $tokens) {}

    public function platform(): Platform
    {
        return Platform::Google;
    }

    public function missing(AdDestination $destination): ?string
    {
        if ($destination->credential('ads_customer_id') === null || ! $destination->hasGoogleAuth('https://www.googleapis.com/auth/adwords') || ! $destination->hasGoogleAuth()) {
            return __('Para Customer Match falta conectar Google (Conexiones) y elegir la cuenta de Google Ads del destino.');
        }

        return null;
    }

    public function create(AdDestination $destination, AudienceSync $sync): array
    {
        $customer = $this->customer($destination);
        $url = GoogleAdsHeaders::url('customers/'.$customer.'/userLists:mutate');
        try {
            $response = Http::timeout(30)->withToken($this->tokens->for($destination))->withHeaders($this->adsHeaders($destination))->asJson()->post($url, [
                'operations' => [['create' => [
                    'name' => mb_substr($sync->name, 0, 200),
                    'description' => __('Segmento sincronizado por Imagina Growth'),
                    'membershipLifeSpan' => 10000,
                    'crmBasedUserList' => ['uploadKeyType' => 'CONTACT_INFO'],
                ]]],
            ]);
        } catch (ConnectionException $e) {
            return [ErrorTranslator::connection('Google', $e->getMessage()), null];
        } catch (RuntimeException $e) {
            return [new DeliveryResult(DeliveryResult::FAILED, 0, 'google:auth', $e->getMessage()), null];
        }
        $resource = $response->json('results.0.resourceName');
        if (! $response->successful() || ! is_string($resource)) {
            return [ErrorTranslator::google($response, 'Google Ads'), null];
        }

        return [DeliveryResult::delivered($response->status()), (string) substr($resource, (int) strrpos($resource, '/') + 1)];
    }

    public function add(AdDestination $destination, string $audienceId, array $members): DeliveryResult
    {
        return $this->members('audienceMembers:ingest', $destination, $audienceId, $members, true);
    }

    public function remove(AdDestination $destination, string $audienceId, array $members): DeliveryResult
    {
        return $this->members('audienceMembers:remove', $destination, $audienceId, $members, false);
    }

    public function identifiers(?string $email, ?string $phone, string $defaultCountry): array
    {
        return array_filter([
            'email' => H::hash(H::email($email)),
            'phone' => H::hash(H::phone($phone, $defaultCountry, Platform::Google)),
        ], fn ($v) => $v !== null);
    }

    /** @return positive-int */
    public function batchSize(): int
    {
        return max(1, (int) config('igr-audiences.batch_size.google', 10000));
    }

    /** @param list<array<string, string>> $members */
    private function members(string $method, AdDestination $destination, string $audienceId, array $members, bool $ingest): DeliveryResult
    {
        if ($members === []) {
            return DeliveryResult::delivered();
        }
        $body = array_filter([
            'destinations' => [[
                'operatingAccount' => ['accountType' => 'GOOGLE_ADS', 'accountId' => $this->customer($destination)],
                'productDestinationId' => $audienceId,
            ]],
            'audienceMembers' => array_map(fn (array $m) => ['userData' => ['userIdentifiers' => array_values(array_filter([
                isset($m['email']) ? ['emailAddress' => $m['email']] : null,
                isset($m['phone']) ? ['phoneNumber' => $m['phone']] : null,
            ]))]], $members),
            'encoding' => 'HEX',
            'consent' => $ingest ? ['adUserData' => 'CONSENT_GRANTED', 'adPersonalization' => 'CONSENT_GRANTED'] : null,
            'termsOfService' => $ingest ? ['customerMatchTermsOfServiceStatus' => 'ACCEPTED'] : null,
        ], fn ($v) => $v !== null);
        $url = rtrim((string) config('igr-audiences.google.data_manager_url'), '/').'/'.$method;
        try {
            $response = Http::timeout(60)->withToken($this->tokens->for($destination))->asJson()->post($url, $body);
        } catch (ConnectionException $e) {
            return ErrorTranslator::connection('Google', $e->getMessage());
        } catch (RuntimeException $e) {
            return new DeliveryResult(DeliveryResult::FAILED, 0, 'google:auth', $e->getMessage());
        }

        return $response->successful() ? DeliveryResult::delivered($response->status()) : ErrorTranslator::google($response, 'Data Manager');
    }

    private function customer(AdDestination $destination): string
    {
        return (string) preg_replace('/\D/', '', (string) $destination->credential('ads_customer_id'));
    }

    /** @return array<string, string> */
    private function adsHeaders(AdDestination $destination): array
    {
        return GoogleAdsHeaders::for($destination);
    }
}
