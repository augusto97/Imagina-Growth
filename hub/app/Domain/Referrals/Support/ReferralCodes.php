<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Sites\Models\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Códigos de referido y de afiliado (`?ref=CODIGO`). El de cada cliente se crea la
 * primera vez que se pide: hasta 4 letras de su nombre más 3 caracteres al azar
 * (`ANA7K2`), sin 0/O ni 1/I y no secuencial. Un código es único en el espacio de
 * trabajo entre clientes y afiliados. Debe correr dentro del contexto del espacio.
 */
final class ReferralCodes
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const PATTERN = '/^[A-Za-z0-9_-]{3,32}$/';

    public function forContact(Contact $contact): string
    {
        $existing = $contact->getAttribute('referral_code');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $prefix = self::namePrefix($contact->first_name);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = $prefix.self::random($prefix === '' ? 6 : 3 + intdiv($attempt, 3));
            if ($this->taken($code)) {
                continue;
            }
            try {
                // Solo si sigue vacío: dos peticiones a la vez no le cambian el código a nadie.
                $updated = Contact::query()->whereKey($contact->id)->whereNull('referral_code')->update(['referral_code' => $code]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
            if ($updated === 0) {
                $code = (string) Contact::query()->whereKey($contact->id)->value('referral_code');
            }
            $contact->setAttribute('referral_code', $code);
            $contact->syncOriginalAttribute('referral_code');

            return $code;
        }

        throw new RuntimeException('No se pudo generar un código de referido único.');
    }

    /** Código nuevo para un afiliado (o el que pidió, si está libre). */
    public function forAffiliate(?string $wanted, string $name): string
    {
        if ($wanted !== null && $wanted !== '') {
            return strtoupper($wanted);
        }
        $prefix = self::namePrefix($name, 6);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = $prefix.self::random($prefix === '' ? 6 : 2 + intdiv($attempt, 3));
            if (! $this->taken($code)) {
                return $code;
            }
        }

        throw new RuntimeException('No se pudo generar un código de afiliado único.');
    }

    /** ¿Ya lo usa un cliente o un afiliado del espacio de trabajo? (sin distinguir mayúsculas) */
    public function taken(string $code, ?string $exceptAffiliateId = null): bool
    {
        $upper = strtoupper($code);

        return Contact::withTrashed()->where('referral_code', $upper)->exists()
            || Affiliate::query()->where('link_code', $upper)->when($exceptAffiliateId !== null, fn ($q) => $q->whereKeyNot($exceptAffiliateId))->exists();
    }

    /**
     * El cliente dueño de un código (siguiendo las fusiones de perfiles).
     */
    public function contactFor(string $code): ?Contact
    {
        if (preg_match(self::PATTERN, $code) !== 1) {
            return null;
        }
        $contact = Contact::withTrashed()->where('referral_code', strtoupper($code))->first();
        for ($hops = 0; $contact !== null && $hops < 5; $hops++) {
            $merged = $contact->getAttribute('merged_into_id');
            if (! is_string($merged) || $merged === '') {
                return $contact->trashed() ? null : $contact;
            }
            $contact = Contact::withTrashed()->find($merged);
        }

        return null;
    }

    public function affiliateFor(string $code): ?Affiliate
    {
        if (preg_match(self::PATTERN, $code) !== 1) {
            return null;
        }

        return Affiliate::query()->where('link_code', strtoupper($code))->where('status', 'active')->first();
    }

    /** `https://tienda.co/?ref=ANA7K2` */
    public static function link(Site $site, string $code): string
    {
        $base = rtrim((string) ($site->url ?: 'https://'.$site->host), '/');

        return $base.'/?ref='.rawurlencode($code);
    }

    private static function namePrefix(?string $name, int $max = 4): string
    {
        $ascii = strtoupper((string) preg_replace('/[^A-Za-z]/', '', Str::ascii(explode(' ', trim((string) $name))[0])));

        return mb_substr($ascii, 0, $max);
    }

    private static function random(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }
}
