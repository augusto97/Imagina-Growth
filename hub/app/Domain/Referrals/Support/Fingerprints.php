<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Support\Pii\BlindIndex;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Señales antifraude de los referidos (modulos.md M14): todo se compara por hash
 * (HMAC con la clave del índice ciego del espacio de trabajo), nunca en claro.
 *
 * - Mismo correo o teléfono: índice ciego del contacto.
 * - Mismo dispositivo: el `anonymous_id` del navegador del amigo es uno de los de quien refiere.
 * - Misma IP: la IP del amigo coincide con una que usó quien refiere en la ventana.
 * - Mismo destinatario: el pedido del amigo va al mismo nombre y ciudad que uno de
 *   quien refiere (el contrato del pedido no trae la dirección de la calle).
 *
 * Las huellas de quien refiere salen de sus pedidos (IP, navegador y destinatario)
 * y de cuando comparte su enlace (IP y navegador).
 */
final class Fingerprints
{
    public const SAME_PERSON = 'same_person';

    public const SAME_DEVICE = 'same_device';

    public const SAME_IP = 'same_ip';

    public const SAME_ADDRESS = 'same_address';

    public static function hash(string $workspaceId, string $kind, string $value): string
    {
        return BlindIndex::value($workspaceId, 'referral-'.$kind, $value);
    }

    /**
     * Destinatario del pedido: nombre, apellido, ciudad, departamento y país normalizados.
     *
     * @param  array<string, mixed>|null  $address
     */
    public static function addressValue(?array $address): ?string
    {
        if ($address === null) {
            return null;
        }
        $norm = fn (mixed $v) => trim((string) preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii(is_scalar($v) ? (string) $v : ''))));
        $first = $norm($address['first_name'] ?? '');
        $last = $norm($address['last_name'] ?? '');
        $city = $norm($address['city'] ?? '');
        if ($last === '' || $city === '') {
            return null;
        }

        return implode('|', [$first, $last, $city, $norm($address['state'] ?? ''), $norm($address['country'] ?? '')]);
    }

    /** Recuerda una huella de un contacto (solo el hash). */
    public function record(string $workspaceId, string $contactId, string $kind, ?string $value, ?CarbonImmutable $at = null): void
    {
        if ($value === null || trim($value) === '') {
            return;
        }
        $hash = self::hash($workspaceId, $kind, $value);
        $at ??= CarbonImmutable::now();
        DB::table('referral_fingerprints')->upsert(
            [['workspace_id' => $workspaceId, 'contact_id' => $contactId, 'kind' => $kind, 'hash' => $hash, 'last_seen_at' => $at]],
            ['workspace_id', 'contact_id', 'kind', 'hash'],
            ['last_seen_at'],
        );
    }

    /**
     * Señales entre quien refiere y el amigo: lo que se sabe del amigo en este momento
     * (`anonymous_id` e IP de la captura) más las huellas que ya dejó (sus pedidos).
     *
     * @param  array{anonymous_id?: string|null, ip?: string|null}  $now
     * @return list<string>
     */
    public function compare(string $workspaceId, Contact $referrer, Contact $friend, array $now, int $windowDays): array
    {
        $signals = [];
        if (self::samePerson($referrer, $friend)) {
            $signals[] = self::SAME_PERSON;
        }
        $anonIds = ContactIdentity::query()->where('contact_id', $friend->id)->where('type', 'anonymous_id')->limit(50)->pluck('value')->map(fn ($v) => (string) $v)->all();
        $anon = $now['anonymous_id'] ?? null;
        if (is_string($anon) && $anon !== '') {
            $anonIds[] = $anon;
        }
        if ($anonIds !== [] && ContactIdentity::query()->where('contact_id', $referrer->id)->where('type', 'anonymous_id')->whereIn('value', array_values(array_unique($anonIds)))->exists()) {
            $signals[] = self::SAME_DEVICE;
        }
        $since = CarbonImmutable::now()->subDays($windowDays);
        $ip = $now['ip'] ?? null;
        $ipNow = is_string($ip) && $ip !== '' && $this->seen($workspaceId, $referrer->id, 'ip', self::hash($workspaceId, 'ip', $ip), $since);
        if ($ipNow || $this->shared($workspaceId, $referrer->id, $friend->id, 'ip', $since)) {
            $signals[] = self::SAME_IP;
        }
        if ($this->shared($workspaceId, $referrer->id, $friend->id, 'address', null)) {
            $signals[] = self::SAME_ADDRESS;
        }

        return $signals;
    }

    public static function samePerson(Contact $a, Contact $b): bool
    {
        if ($a->id === $b->id) {
            return true;
        }
        foreach (['email_bidx', 'phone_bidx'] as $column) {
            $x = $a->getAttribute($column);
            if (is_string($x) && $x !== '' && $x === $b->getAttribute($column)) {
                return true;
            }
        }

        return false;
    }

    /** ¿Las dos personas dejaron la misma huella? */
    private function shared(string $workspaceId, string $a, string $b, string $kind, ?CarbonImmutable $since): bool
    {
        return DB::table('referral_fingerprints as x')
            ->join('referral_fingerprints as y', function ($join) use ($b) {
                $join->on('y.workspace_id', '=', 'x.workspace_id')->on('y.kind', '=', 'x.kind')->on('y.hash', '=', 'x.hash')->where('y.contact_id', '=', $b);
            })
            ->where('x.workspace_id', $workspaceId)->where('x.contact_id', $a)->where('x.kind', $kind)
            ->when($since !== null, fn ($q) => $q->where('x.last_seen_at', '>=', $since)->where('y.last_seen_at', '>=', $since))
            ->exists();
    }

    private function seen(string $workspaceId, string $contactId, string $kind, string $hash, ?CarbonImmutable $since): bool
    {
        return DB::table('referral_fingerprints')->where('workspace_id', $workspaceId)->where('contact_id', $contactId)
            ->where('kind', $kind)->where('hash', $hash)
            ->when($since !== null, fn ($q) => $q->where('last_seen_at', '>=', $since))
            ->exists();
    }
}
