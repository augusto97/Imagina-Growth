<?php

declare(strict_types=1);

namespace App\Support\Phone;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/** Normalización a E.164 con libphonenumber y el país por defecto del espacio de trabajo. */
final class Phone
{
    public static function toE164(?string $raw, string $defaultCountry = 'CO'): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse(trim($raw), strtoupper($defaultCountry));
        } catch (NumberParseException) {
            return null;
        }
        if (! $util->isValidNumber($number)) {
            return null;
        }

        return $util->format($number, PhoneNumberFormat::E164);
    }
}
