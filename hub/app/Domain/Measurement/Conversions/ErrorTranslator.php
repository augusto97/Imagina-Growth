<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Conversions;

use Illuminate\Http\Client\Response;

/**
 * Traduce los errores de cada plataforma a un mensaje humano en español y
 * decide si vale la pena reintentar (hub/CLAUDE.md §11: «el panel muestra los
 * errores con un mensaje humano»).
 */
final class ErrorTranslator
{
    public static function meta(Response $response): DeliveryResult
    {
        $error = (array) ($response->json('error') ?? []);
        $code = (int) ($error['code'] ?? 0);
        $sub = (int) ($error['error_subcode'] ?? 0);
        $codeLabel = $code.($sub !== 0 ? '/'.$sub : '');

        $message = match (true) {
            $code === 190 => __('El token de acceso de Meta no es válido o venció. Genera uno nuevo en el Administrador de eventos y actualiza el destino.'),
            in_array($code, [10, 200, 294], true) => __('El token no tiene permiso sobre ese conjunto de datos o píxel. Revisa que el usuario del sistema esté asignado al conjunto de datos.'),
            in_array($code, [4, 17, 32, 613, 80004], true) => __('Meta limitó temporalmente las peticiones. Se reintentará solo.'),
            $code === 100 && $sub === 2804003 => __('Meta rechazó el evento por ser demasiado viejo.'),
            $code === 100 && in_array($sub, [33, 2804019], true) => __('El ID del conjunto de datos (píxel) no existe o no es accesible.'),
            $code === 100 => __('Meta rechazó un dato del evento (parámetro inválido). Detalle: :msg', ['msg' => self::short((string) ($error['message'] ?? ''))]),
            default => self::byStatus($response, 'Meta'),
        };
        $retry = $response->serverError() || in_array($code, [1, 2, 4, 17, 32, 613, 80004], true) || $response->status() === 429;

        return new DeliveryResult($retry ? DeliveryResult::RETRY : DeliveryResult::FAILED, $response->status(), 'meta:'.$codeLabel, $message);
    }

    public static function google(Response $response, string $api): DeliveryResult
    {
        $error = (array) ($response->json('error') ?? []);
        $status = (string) ($error['status'] ?? '');
        $message = match (true) {
            $response->status() === 401 || $status === 'UNAUTHENTICATED' => __('Google rechazó las credenciales (:api). Vuelve a autorizar la cuenta en el destino.', ['api' => $api]),
            $response->status() === 403 || $status === 'PERMISSION_DENIED' => __('La cuenta autorizada no tiene acceso a esa cuenta de Google Ads o a esa acción de conversión (:api).', ['api' => $api]),
            $response->status() === 404 => __('La cuenta o la acción de conversión no existe (:api).', ['api' => $api]),
            $response->status() === 400 || $status === 'INVALID_ARGUMENT' => __('Google rechazó un dato del evento (:api). Detalle: :msg', ['api' => $api, 'msg' => self::short((string) ($error['message'] ?? ''))]),
            default => self::byStatus($response, 'Google'),
        };
        $retry = $response->serverError() || $response->status() === 429;

        return new DeliveryResult($retry ? DeliveryResult::RETRY : DeliveryResult::FAILED, $response->status(), 'google:'.($status !== '' ? $status : $response->status()), $message);
    }

    /** TikTok responde 200 con un `code` distinto de 0 cuando falla. */
    public static function tiktok(Response $response): DeliveryResult
    {
        $code = (int) ($response->json('code') ?? ($response->successful() ? 0 : -1));
        $message = match (true) {
            in_array($code, [40001, 40100, 40105], true) => __('El token de acceso de TikTok no es válido o venció. Genera uno nuevo en el Administrador de eventos de TikTok.'),
            in_array($code, [40002, 40006], true) => __('TikTok rechazó un dato del evento. Detalle: :msg', ['msg' => self::short((string) ($response->json('message') ?? ''))]),
            $code === 40016 => __('El código del píxel de TikTok no existe o no pertenece a la cuenta.'),
            in_array($code, [40100, 50002], true) || $response->status() === 429 => __('TikTok limitó temporalmente las peticiones. Se reintentará solo.'),
            default => self::byStatus($response, 'TikTok'),
        };
        $retry = $response->serverError() || $response->status() === 429 || in_array($code, [50000, 50002, 51000], true);

        return new DeliveryResult($retry ? DeliveryResult::RETRY : DeliveryResult::FAILED, $response->status(), 'tiktok:'.$code, $message);
    }

    public static function connection(string $platform, string $detail): DeliveryResult
    {
        return new DeliveryResult(DeliveryResult::RETRY, 0, 'connection', __('No se pudo conectar con :platform (:detail). Se reintentará.', ['platform' => $platform, 'detail' => self::short($detail)]));
    }

    private static function byStatus(Response $response, string $platform): string
    {
        return match (true) {
            $response->status() === 429 => __(':platform limitó temporalmente las peticiones. Se reintentará solo.', ['platform' => $platform]),
            $response->serverError() => __(':platform tuvo un error temporal (HTTP :status). Se reintentará.', ['platform' => $platform, 'status' => $response->status()]),
            default => __(':platform respondió con un error (HTTP :status).', ['platform' => $platform, 'status' => $response->status()]),
        };
    }

    private static function short(string $text): string
    {
        return mb_substr(trim($text), 0, 200);
    }
}
