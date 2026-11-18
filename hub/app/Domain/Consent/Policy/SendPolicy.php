<?php

declare(strict_types=1);

namespace App\Domain\Consent\Policy;

use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Motor de política de envío (cumplimiento.md §4.2, hub/CLAUDE.md §8). Decide
 * Allow, Defer(until) o Deny antes de cada mensaje, en este orden:
 *
 * 1. Cuenta del canal: activa; la conexión no oficial (Zender) nunca envía
 *    marketing; con la calidad de WhatsApp en rojo no sale marketing y en amarillo
 *    se frenan las campañas.
 * 2. Contacto: tiene la dirección del canal; no está suprimido (rebote, queja,
 *    bloqueo: nada sale; baja o registro de exclusión: no sale marketing).
 * 3. Marketing: consentimiento `granted` del canal.
 * 4. WhatsApp de servicio: solo dentro de la ventana de 24 h de la conversación.
 * 5. Marketing: ventana legal del país del contacto (LegalWindow) → se aplaza.
 * 6. Marketing: frecuencia (por día sumando canales, por semana, un canal por
 *    semana opcional y la preferencia del contacto) → se aplaza.
 *
 * Los contadores de frecuencia viven en Redis por contacto y se reservan de forma
 * atómica (`checkAndReserve`), así dos envíos simultáneos no pasan los dos.
 */
final class SendPolicy
{
    private const HARD_SUPPRESSIONS = ['bounce', 'complaint', 'wa_block', 'manual'];

    /**
     * Revisa los contadores de frecuencia y, si `reserve` = 1, los incrementa en
     * la misma operación. Devuelve ok, day, week, channel o pref.
     */
    private const FREQUENCY_SCRIPT = <<<'LUA'
        local perDay = tonumber(ARGV[1])
        local perWeek = tonumber(ARGV[2])
        local d = tonumber(redis.call('GET', KEYS[1]) or '0')
        if d >= perDay then return 'day' end
        local w = tonumber(redis.call('GET', KEYS[2]) or '0')
        if w >= perWeek then return 'week' end
        if ARGV[3] == '1' then
          local chans = redis.call('SMEMBERS', KEYS[3])
          for _, c in ipairs(chans) do
            if c ~= ARGV[4] then return 'channel' end
          end
        end
        local perMonth = tonumber(ARGV[5])
        if perMonth > 0 then
          local m = tonumber(redis.call('GET', KEYS[4]) or '0')
          if m >= perMonth then return 'pref' end
        end
        if ARGV[6] == '1' then
          redis.call('INCR', KEYS[1]) redis.call('EXPIRE', KEYS[1], 691200)
          redis.call('INCR', KEYS[2]) redis.call('EXPIRE', KEYS[2], 1296000)
          redis.call('SADD', KEYS[3], ARGV[4]) redis.call('EXPIRE', KEYS[3], 1296000)
          redis.call('INCR', KEYS[4]) redis.call('EXPIRE', KEYS[4], 3456000)
        end
        return 'ok'
        LUA;

    /** Revisión sin reservar (vista previa, revisión de cumplimiento de una campaña). */
    public function check(PolicyRequest $request): PolicyDecision
    {
        return $this->evaluate($request, false);
    }

    /** Revisión que, si permite el envío, reserva el cupo de frecuencia. La usa MessageDispatcher. */
    public function checkAndReserve(PolicyRequest $request): PolicyDecision
    {
        return $this->evaluate($request, true);
    }

    /**
     * Devuelve el cupo de frecuencia reservado si el envío no llegó al proveedor
     * (por ejemplo, la plantilla falló). `reservedAt` es el momento de la reserva.
     */
    public function release(PolicyRequest $request, CarbonImmutable $reservedAt): void
    {
        if ($request->contact === null || ! $request->kind->isCommercial()) {
            return;
        }
        $keys = $this->frequencyKeys($request, $reservedAt);
        $redis = Redis::connection();
        foreach ([$keys['day'], $keys['week'], $keys['month']] as $key) {
            if ((int) $redis->get($key) > 0) {
                $redis->decr($key);
            }
        }
    }

    /**
     * Solo la ventana legal: el job de envío la vuelve a mirar justo antes de
     * llamar al proveedor (una cola lenta no puede sacar un mensaje a las 19:01).
     */
    public function windowAllows(PolicyRequest $request, ?CarbonImmutable $at = null): PolicyDecision
    {
        if ($request->isTest || ! $request->kind->isCommercial()) {
            return PolicyDecision::allow();
        }
        [$country, $tz] = $this->locale($request);
        $at = ($at ?? $request->at())->utc();
        $closed = LegalWindow::closedReason($country, $at, $tz);

        return $closed === null ? PolicyDecision::allow() : PolicyDecision::defer(LegalWindow::nextOpen($country, $at, $tz), $closed);
    }

    private function evaluate(PolicyRequest $request, bool $reserve): PolicyDecision
    {
        if ($request->isTest) {
            return PolicyDecision::allow();
        }
        $commercial = $request->kind->isCommercial();

        // 1. Cuenta del canal.
        $account = $request->account ?? ChannelAccount::defaultFor($request->channel);
        if ($account === null || ! $account->isActive()) {
            return PolicyDecision::deny(PolicyDecision::CHANNEL_UNAVAILABLE);
        }
        if ($commercial && $account->provider === ChannelAccount::ZENDER) {
            return PolicyDecision::deny(PolicyDecision::UNOFFICIAL_MARKETING);
        }
        if ($commercial && $request->channel === Channel::WhatsApp) {
            $quality = strtoupper((string) $account->quality_rating);
            if ($quality === 'RED' || ($quality === 'YELLOW' && $request->sourceType === 'campaign')) {
                return PolicyDecision::deny(PolicyDecision::QUALITY_LOW);
            }
        }

        // Avisos al equipo y destinos explícitos: sin contacto que revisar.
        if ($request->hasExplicitRecipient) {
            return $commercial ? $this->windowAllows($request) : PolicyDecision::allow();
        }

        // 2. Contacto: dirección y supresión.
        $contact = $request->contact;
        $bidx = $contact === null ? null : ($request->channel === Channel::Email ? $contact->email_bidx : $contact->phone_bidx);
        if ($contact === null || $bidx === null || $bidx === '') {
            return PolicyDecision::deny(PolicyDecision::NO_ADDRESS);
        }
        $suppressions = DB::table('suppressions')->where('workspace_id', $request->workspace->id)
            ->where('channel', $request->channel->value)->where('value_bidx', $bidx)->pluck('reason')->all();
        if (array_intersect($suppressions, self::HARD_SUPPRESSIONS) !== []) {
            return PolicyDecision::deny(PolicyDecision::SUPPRESSED);
        }
        if ($commercial && in_array('unsubscribe', $suppressions, true)) {
            return PolicyDecision::deny(PolicyDecision::UNSUBSCRIBED);
        }
        if ($commercial && array_intersect($suppressions, ['rne', 'repep']) !== []) {
            return PolicyDecision::deny(PolicyDecision::EXCLUDED_REGISTRY);
        }

        // 3. Consentimiento de marketing del canal.
        if ($commercial && ! $this->hasMarketingConsent($contact, $request->channel)) {
            return PolicyDecision::deny(PolicyDecision::NO_CONSENT);
        }

        // 4. Mensaje libre de WhatsApp: solo con la ventana de 24 h abierta.
        if ($request->channel === Channel::WhatsApp && $request->kind->value === 'service') {
            $open = DB::table('conversations')->where('workspace_id', $request->workspace->id)
                ->where('contact_id', $contact->id)->where('channel_account_id', $account->id)
                ->where('window_expires_at', '>', $request->at())->exists();
            if (! $open) {
                return PolicyDecision::deny(PolicyDecision::OUTSIDE_24H_WINDOW);
            }
        }

        if (! $commercial) {
            return PolicyDecision::allow();
        }

        // 5. Ventana legal.
        $window = $this->windowAllows($request);
        if (! $window->allowed()) {
            return $window;
        }

        // 6. Frecuencia.
        return $this->frequency($request, $reserve);
    }

    private function hasMarketingConsent(Contact $contact, Channel $channel): bool
    {
        return ContactConsent::query()->where('contact_id', $contact->id)->where('channel', $channel->value)
            ->where('purpose', 'marketing')->where('status', 'granted')->exists();
    }

    private function frequency(PolicyRequest $request, bool $reserve): PolicyDecision
    {
        $at = $request->at();
        /** @var array{marketing_per_day?: int, marketing_per_week?: int, one_channel_per_week?: bool} $limits */
        $limits = (array) $request->workspace->setting('frequency', []);
        $perDay = max(1, (int) ($limits['marketing_per_day'] ?? 1));
        $perWeek = max(1, (int) ($limits['marketing_per_week'] ?? 3));
        $preference = $request->contact !== null
            ? (string) (DB::table('contact_preferences')->where('contact_id', $request->contact->id)->value('frequency') ?? 'normal')
            : 'normal';
        if ($preference === 'weekly') {
            $perWeek = 1;
        }
        $keys = $this->frequencyKeys($request, $at);
        $result = (string) Redis::connection()->eval(
            self::FREQUENCY_SCRIPT, 4,
            $keys['day'], $keys['week'], $keys['channels'], $keys['month'],
            $perDay, $perWeek, ! empty($limits['one_channel_per_week']) ? '1' : '0', $request->channel->value, $preference === 'monthly' ? 1 : 0, $reserve ? '1' : '0',
        );
        [$country, $tz] = $this->locale($request);

        return match ($result) {
            'ok' => PolicyDecision::allow(),
            'day' => PolicyDecision::defer(LegalWindow::nextDayOpen($country, $at, $tz), PolicyDecision::FREQUENCY_DAY),
            'week' => PolicyDecision::defer(LegalWindow::nextWeekOpen($country, $at, $tz), $preference === 'weekly' ? PolicyDecision::FREQUENCY_PREFERENCE : PolicyDecision::FREQUENCY_WEEK),
            'channel' => PolicyDecision::defer(LegalWindow::nextWeekOpen($country, $at, $tz), PolicyDecision::FREQUENCY_CHANNEL_WEEK),
            default => PolicyDecision::defer(
                LegalWindow::nextOpen($country, $at->setTimezone(LegalWindow::timezoneFor($country, $tz))->startOfMonth()->addMonth(), $tz),
                PolicyDecision::FREQUENCY_PREFERENCE,
            ),
        };
    }

    /**
     * Claves por contacto con fechas locales (el «día» es el del contacto).
     *
     * @return array{day: string, week: string, channels: string, month: string}
     */
    private function frequencyKeys(PolicyRequest $request, CarbonImmutable $at): array
    {
        [$country, $tz] = $this->locale($request);
        $local = $at->setTimezone(LegalWindow::timezoneFor($country, $tz));
        $base = 'ws:'.$request->workspace->id.':freq:'.($request->contact->id ?? 'none');

        return [
            'day' => $base.':d:'.$local->format('Ymd'),
            'week' => $base.':w:'.$local->format('oW'),
            'channels' => $base.':wc:'.$local->format('oW'),
            'month' => $base.':m:'.$local->format('Ym'),
        ];
    }

    /** @return array{0: string, 1: string|null} país y zona horaria del contacto */
    private function locale(PolicyRequest $request): array
    {
        $country = strtoupper((string) ($request->contact?->getAttribute('country') ?: $request->workspace->country ?: 'CO'));
        $tz = $request->contact?->getAttribute('timezone');

        return [$country, is_string($tz) && $tz !== '' ? $tz : null];
    }
}
