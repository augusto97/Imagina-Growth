<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifica un mensaje de Amazon SNS (eventos de SES) antes de creerle (S6):
 *
 * 1. El tema (`TopicArn`) debe ser uno de los configurados en `IGR_SES_SNS_TOPIC_ARN` (separados por comas). Sin
 *    tema configurado se rechaza todo: de lo contrario cualquiera con una cuenta de AWS podría mandar rebotes y quejas
 *    falsos desde su propio tema (con firma válida de SNS) y suprimir correos de los clientes.
 * 2. El certificado debe venir de `sns.<región>.amazonaws.com` y la región debe ser la del tema.
 * 3. La firma cubre los campos que AWS documenta para cada tipo de mensaje (SignatureVersion 1 = SHA1, 2 = SHA256).
 *
 * El certificado se guarda en caché un día, solo si la descarga salió bien y es un PEM.
 */
final class SnsMessageVerifier
{
    private const NOTIFICATION = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];

    private const SUBSCRIPTION = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

    private const TYPES = ['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation'];

    /** @param array<string, mixed> $message */
    public function verify(array $message): bool
    {
        $certUrl = (string) ($message['SigningCertURL'] ?? '');
        if (preg_match('#^https://sns\.([a-z0-9-]+)\.amazonaws\.com(\.cn)?/[A-Za-z0-9/_.-]+\.pem$#', $certUrl, $cert) !== 1) {
            return false;
        }
        $topic = (string) ($message['TopicArn'] ?? '');
        if (! self::topicAllowed($topic)) {
            Log::warning(self::allowedTopics() === [] ? 'igr.messaging.sns_topic_not_configured' : 'igr.messaging.sns_unknown_topic');

            return false;
        }
        if (preg_match('/^arn:aws(?:-cn|-us-gov)?:sns:([a-z0-9-]+):\d{1,12}:[A-Za-z0-9_.-]{1,256}$/', $topic, $arn) !== 1 || $arn[1] !== $cert[1]) {
            return false;
        }
        $version = (string) ($message['SignatureVersion'] ?? '');
        $type = (string) ($message['Type'] ?? '');
        if (! in_array($version, ['1', '2'], true) || ! in_array($type, self::TYPES, true)) {
            return false;
        }
        $pem = $this->certificate($certUrl);
        $signature = base64_decode((string) ($message['Signature'] ?? ''), true);
        $key = $pem !== null ? openssl_pkey_get_public($pem) : false;
        if ($signature === false || $key === false) {
            return false;
        }
        $data = '';
        foreach ($type === 'Notification' ? self::NOTIFICATION : self::SUBSCRIPTION as $field) {
            if (isset($message[$field]) && is_scalar($message[$field])) {
                $data .= $field."\n".$message[$field]."\n";
            }
        }

        return openssl_verify($data, $signature, $key, $version === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1) === 1;
    }

    /** @return list<string> los ARN de `igr-messaging.ses.sns_topic_arn` */
    public static function allowedTopics(): array
    {
        $raw = (string) config('igr-messaging.ses.sns_topic_arn');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $t) => $t !== ''));
    }

    public static function topicAllowed(string $topic): bool
    {
        return $topic !== '' && in_array($topic, self::allowedTopics(), true);
    }

    private function certificate(string $url): ?string
    {
        $cacheKey = 'sns:cert:'.sha1($url);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && str_contains($cached, 'BEGIN CERTIFICATE')) {
            return $cached;
        }
        try {
            $response = Http::timeout(5)->withoutRedirecting()->get($url);
        } catch (Throwable) {
            return null;
        }
        $pem = $response->body();
        if (! $response->successful() || ! str_contains($pem, 'BEGIN CERTIFICATE')) {
            return null;
        }
        Cache::put($cacheKey, $pem, 86400);

        return $pem;
    }
}
