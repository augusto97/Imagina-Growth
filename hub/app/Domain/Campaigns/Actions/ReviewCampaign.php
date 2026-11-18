<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Actions;

use App\Domain\Campaigns\Audience\AudienceQuery;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Support\SendSchedule;
use App\Domain\Campaigns\Support\WaRateCard;
use App\Domain\Commerce\Projectors\FxConverter;
use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Paso 5 del asistente (modulos.md M10): cuántos recibirán la campaña, cuánto
 * costará y quiénes quedan fuera y por qué, antes de aprobarla.
 *
 * La revisión de cumplimiento usa SendPolicy::check (sin reservar cupos) a la
 * hora planeada de cada contacto. Con audiencias grandes revisa una muestra
 * aleatoria de SAMPLE contactos y extrapola (`estimated: true`).
 */
final class ReviewCampaign
{
    public const SAMPLE = 3000;

    /** Costo de un correo con Amazon SES, en USD **[verificar]** (USD 0,10 por cada 1.000). */
    public const EMAIL_RATE_USD = 0.0001;

    public function __construct(
        private readonly AudienceQuery $audience,
        private readonly SendPolicy $policy,
        private readonly FxConverter $fx,
    ) {}

    /**
     * @return array{cost: array<string, mixed>, compliance: array<string, mixed>}
     */
    public function handle(Workspace $workspace, Campaign $campaign, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $total = $this->audience->count($workspace, $campaign->audience ?? []);
        $holdout = (int) round($total * $campaign->holdout_pct / 100);
        $reachable = $total - $holdout;

        $compliance = $this->compliance($workspace, $campaign, $total, $now);
        $cost = $this->cost($workspace, $campaign, $compliance['allowed_ratio'], $reachable, $now);
        unset($compliance['allowed_ratio']);
        $compliance['holdout'] = $holdout;
        $compliance['audience'] = $total;

        $campaign->forceFill(['cost_estimate' => $cost, 'compliance_check' => $compliance])->save();

        return ['cost' => $cost, 'compliance' => $compliance];
    }

    /** @return array<string, mixed> */
    private function compliance(Workspace $workspace, Campaign $campaign, int $total, CarbonImmutable $now): array
    {
        $channel = Channel::from($campaign->channel);
        $account = ChannelAccount::defaultFor($channel);
        $query = $this->audience->query($workspace, $campaign->audience ?? [])->select('c.id');
        $estimated = $total > self::SAMPLE;
        $ids = $estimated
            ? $query->inRandomOrder()->limit(self::SAMPLE)->pluck('c.id')->all()
            : $query->pluck('c.id')->all();

        $allowed = 0;
        $deferred = [];
        $denied = [];
        $firstSend = null;
        $lastSend = null;
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Contact::query()->whereIn('id', $chunk)->get() as $contact) {
                $at = SendSchedule::forContact($campaign, $workspace, $contact->getAttribute('country'), $contact->getAttribute('timezone'), self::hour($contact), $now);
                $decision = $this->policy->check(new PolicyRequest($workspace, $contact, $channel, MessageKind::Marketing, 'campaign', $account, $at));
                if ($decision->allowed()) {
                    $allowed++;
                    $firstSend = $firstSend === null || $at->lessThan($firstSend) ? $at : $firstSend;
                    $lastSend = $lastSend === null || $at->greaterThan($lastSend) ? $at : $lastSend;
                } elseif ($decision->deferred()) {
                    $deferred[(string) $decision->reason] = ($deferred[(string) $decision->reason] ?? 0) + 1;
                } else {
                    $denied[(string) $decision->reason] = ($denied[(string) $decision->reason] ?? 0) + 1;
                }
            }
        }
        $checked = count($ids);
        $scale = $checked > 0 ? $total / $checked : 0.0;
        $bucket = fn (array $counts): array => collect($counts)
            ->map(fn (int $n, string $reason) => ['reason' => $reason, 'label' => PolicyDecision::label($reason), 'count' => (int) round($n * $scale)])
            ->sortByDesc('count')->values()->all();

        return [
            'checked_at' => $now->toIso8601String(),
            'checked' => $checked,
            'estimated' => $estimated,
            'channel_available' => $account !== null && $account->isActive(),
            'will_send' => (int) round($allowed * $scale),
            'deferred' => $bucket($deferred),
            'excluded' => $bucket($denied),
            'excluded_total' => (int) round(array_sum($denied) * $scale),
            'first_send_at' => $firstSend?->toIso8601String(),
            'last_send_at' => $lastSend?->toIso8601String(),
            'allowed_ratio' => $checked > 0 ? ($allowed + array_sum($deferred)) / $checked : 0.0,
        ];
    }

    /**
     * Costo estimado: WhatsApp por país del contacto × tarifa de marketing; correo
     * a la tarifa de SES; SMS sin tarifa (depende del proveedor).
     *
     * @return array<string, mixed>
     */
    private function cost(Workspace $workspace, Campaign $campaign, float $sendRatio, int $reachable, CarbonImmutable $now): array
    {
        $byCountry = [];
        $totalUsd = 0.0;
        $missingRates = false;
        $recipients = (int) round($reachable * $sendRatio);
        if ($campaign->channel === 'whatsapp') {
            $rows = $this->audience->query($workspace, $campaign->audience ?? [])
                ->selectRaw("UPPER(COALESCE(NULLIF(c.country, ''), ?)) AS country, COUNT(*) AS n", [$workspace->country ?: 'CO'])
                ->groupBy('country')->get();
            $audienceTotal = max(1, (int) $rows->sum('n'));
            foreach ($rows as $row) {
                $count = (int) round(((int) $row->n) * $recipients / $audienceTotal);
                $rate = WaRateCard::rate((string) $row->country, 'marketing', $now);
                $missingRates = $missingRates || $rate === null;
                $usd = $count * ($rate ?? 0.0);
                $totalUsd += $usd;
                $byCountry[] = ['country' => (string) $row->country, 'recipients' => $count, 'rate_usd' => $rate, 'total_usd' => round($usd, 4)];
            }
        } elseif ($campaign->channel === 'email') {
            $totalUsd = $recipients * self::EMAIL_RATE_USD;
        }
        $currency = $workspace->currency ?: 'USD';
        $fx = $currency === 'USD' ? '1' : $this->fx->rate('USD', $currency, $now);
        $latest = WaRateCard::latestEffectiveFrom();

        return [
            'channel' => $campaign->channel,
            'recipients' => $recipients,
            'by_country' => $byCountry,
            'total_usd' => round($totalUsd, 4),
            'currency' => $currency,
            'total_local' => $fx !== null ? round($totalUsd * (float) $fx, 2) : null,
            'rates_effective_from' => $latest?->toDateString(),
            'rates_stale' => $campaign->channel === 'whatsapp' && WaRateCard::isStale($now),
            'missing_rates' => $missingRates,
            'note' => match ($campaign->channel) {
                'whatsapp' => __('Meta cobra cada mensaje entregado directamente a tu cuenta de WhatsApp Business.'),
                'email' => __('Estimado con la tarifa de Amazon SES.'),
                default => __('El costo del SMS depende de tu proveedor.'),
            },
        ];
    }

    public static function hour(Contact $contact): ?int
    {
        $hour = $contact->getAttribute('preferred_hour');

        return $hour === null ? null : (int) $hour;
    }
}
