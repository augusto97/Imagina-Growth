<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifica la firma de un mensaje de Amazon SNS (eventos de SES): el certificado
 * debe venir de sns.<región>.amazonaws.com y la firma cubre los campos que AWS
 * documenta para cada tipo de mensaje (SignatureVersion 1 = SHA1, 2 = SHA256).
 */
final class SnsMessageVerifier
{
    private const NOTIFICATION = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];

    private const SUBSCRIPTION = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

    /** @param array<string, mixed> $message */
    public function verify(array $message): bool
    {
        $certUrl = (string) ($message['SigningCertURL'] ?? '');
        if (preg_match('#^https://sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?/[A-Za-z0-9/_.-]+\.pem$#', $certUrl) !== 1) {
            return false;
        }
        $topic = config('igr-messaging.ses.sns_topic_arn');
        if (is_string($topic) && $topic !== '' && ($message['TopicArn'] ?? null) !== $topic) {
            return false;
        }
        $pem = Cache::remember('sns:cert:'.sha1($certUrl), 86400, fn () => Http::timeout(5)->get($certUrl)->body());
        $fields = ($message['Type'] ?? '') === 'Notification' ? self::NOTIFICATION : self::SUBSCRIPTION;
        $data = '';
        foreach ($fields as $field) {
            if (isset($message[$field]) && is_scalar($message[$field])) {
                $data .= $field."\n".$message[$field]."\n";
            }
        }
        $signature = base64_decode((string) ($message['Signature'] ?? ''), true);
        $key = openssl_pkey_get_public($pem);
        if ($signature === false || $key === false) {
            return false;
        }
        $algo = (string) ($message['SignatureVersion'] ?? '1') === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

        return openssl_verify($data, $signature, $key, $algo) === 1;
    }
}
