<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Support;

use App\Domain\Measurement\Enums\Platform;
use App\Support\Phone\Phone;
use Normalizer;

/**
 * Normalización y SHA-256 de los datos coincidentes (eventos.md §7). Lo que
 * cambia según la plataforma está explícito:
 *  - Teléfono: Meta pide solo dígitos con el indicativo (sin «+»); Google y
 *    TikTok, E.164 con «+» [verificar TikTok].
 *  - Nombres: Meta acepta UTF-8 (conserva tildes en minúsculas); Google y
 *    TikTok, sin tildes [verificar].
 */
final class UserDataHasher
{
    public static function hash(?string $normalized): ?string
    {
        return $normalized === null || $normalized === '' ? null : hash('sha256', $normalized);
    }

    public static function email(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $email = mb_strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function phone(?string $phone, string $defaultCountry, Platform $platform): ?string
    {
        $e164 = Phone::toE164($phone, $defaultCountry);
        if ($e164 === null) {
            return null;
        }

        return $platform === Platform::Meta ? ltrim($e164, '+') : $e164;
    }

    public static function name(?string $name, Platform $platform): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $name = mb_strtolower(trim($name));
        if ($platform !== Platform::Meta) {
            $name = self::stripAccents($name);
        }

        return (string) preg_replace('/[\p{P}\s]+/u', '', $name) ?: null;
    }

    public static function city(?string $city): ?string
    {
        if ($city === null || trim($city) === '') {
            return null;
        }

        return (string) preg_replace('/[^a-z0-9]/', '', self::stripAccents(mb_strtolower($city))) ?: null;
    }

    /** Departamento o estado: la parte del código ISO 3166-2 después del guion, en minúsculas («CO-CAL» → «cal»). */
    public static function state(?string $state): ?string
    {
        if ($state === null || trim($state) === '') {
            return null;
        }
        $state = trim($state);
        if (str_contains($state, '-')) {
            $state = substr($state, (int) strpos($state, '-') + 1);
        }

        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($state)) ?: null;
    }

    public static function zip(?string $zip): ?string
    {
        if ($zip === null) {
            return null;
        }

        return (string) preg_replace('/\s+/', '', mb_strtolower(trim($zip))) ?: null;
    }

    public static function country(?string $country): ?string
    {
        return $country !== null && preg_match('/^[A-Za-z]{2}$/', trim($country)) ? strtolower(trim($country)) : null;
    }

    public static function stripAccents(string $value): string
    {
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);

        return (string) preg_replace('/\p{Mn}/u', '', $decomposed === false ? $value : $decomposed);
    }
}
