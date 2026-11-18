<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Support;

use App\Domain\Reviews\Models\ReviewRequest;
use App\Domain\Surveys\Models\SurveyRequest;
use Carbon\CarbonInterface;

/**
 * Tokens de las páginas públicas de reseñas (api-connector.md §8.3): /r/{token}
 * (formulario de reseña, firmado por solicitud, pedido y contacto) y
 * /r/nps/{token} (encuesta NPS o CSAT). Van firmados con la clave de la app
 * (HMAC-SHA256, propósito incluido para que un token no sirva en la otra
 * página) y vencen. No llevan datos personales: solo IDs internos.
 *
 * Formato: base64url(JSON) "." 32 hex de la firma.
 */
final class ReviewToken
{
    public const REVIEW = 'review';

    public const SURVEY = 'survey';

    public static function forRequest(ReviewRequest $request): string
    {
        return self::make(self::REVIEW, [
            'w' => $request->workspace_id, 'r' => $request->id, 'o' => $request->order_id, 'c' => $request->contact_id,
        ], $request->expires_at ?? now()->addDays(60));
    }

    public static function forSurvey(SurveyRequest $request): string
    {
        return self::make(self::SURVEY, [
            'w' => $request->workspace_id, 'r' => $request->id, 'c' => $request->contact_id,
        ], $request->expires_at ?? now()->addDays(30));
    }

    public static function reviewUrl(ReviewRequest $request): string
    {
        return self::base().'/r/'.self::forRequest($request);
    }

    public static function surveyUrl(SurveyRequest $request): string
    {
        return self::base().'/r/nps/'.self::forSurvey($request);
    }

    /**
     * @param  array<string, string>  $claims
     */
    public static function make(string $purpose, array $claims, CarbonInterface $expires): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode($claims + ['x' => $expires->getTimestamp()])), '+/', '-_'), '=');

        return $payload.'.'.self::sign($purpose, $payload);
    }

    /**
     * @return array{status: 'ok', claims: array<string, string|int>}|array{status: 'invalid'|'expired', claims: null}
     */
    public static function parse(string $purpose, string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || strlen($parts[1]) !== 32 || ! hash_equals(self::sign($purpose, $parts[0]), $parts[1])) {
            return ['status' => 'invalid', 'claims' => null];
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($data) || ! is_string($data['w'] ?? null) || ! is_string($data['r'] ?? null) || ! is_int($data['x'] ?? null)) {
            return ['status' => 'invalid', 'claims' => null];
        }
        if ($data['x'] < time()) {
            return ['status' => 'expired', 'claims' => null];
        }

        /** @var array<string, string|int> $data */
        return ['status' => 'ok', 'claims' => $data];
    }

    public static function base(): string
    {
        return rtrim((string) (config('igr-reviews.public_url') ?: config('app.url')), '/');
    }

    private static function sign(string $purpose, string $payload): string
    {
        return substr(hash_hmac('sha256', 'igr-review-link|'.$purpose.'|'.$payload, (string) config('app.key')), 0, 32);
    }
}
