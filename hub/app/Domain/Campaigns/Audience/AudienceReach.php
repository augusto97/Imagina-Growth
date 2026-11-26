<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Audience;

use App\Domain\Campaigns\Support\WaRateCard;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Money\ExchangeRates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A quién llega de verdad una audiencia (asistente de campañas, paso «A quién»): cuántos contactos tiene y, por canal,
 * cuántos aceptaron recibir marketing por él (consentimiento vigente) y si el canal está conectado. Para WhatsApp, el
 * costo aproximado de escribirle a todos ellos con la tarifa de marketing de Meta de su país (WaRateCard), en dólares
 * y en la moneda del negocio. La cifra exacta (horario, frecuencia, supresiones) la da la revisión (ReviewCampaign).
 */
final class AudienceReach
{
    public const CHANNELS = ['whatsapp', 'email', 'sms'];

    public function __construct(private readonly AudienceQuery $audience, private readonly ExchangeRates $fx) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return array{total: int, channels: array<string, array{consented: int, connected: bool}>, whatsapp_cost: array{usd: float, local: float|null, currency: string}|null}
     */
    public function handle(Workspace $workspace, array $definition, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $total = $this->audience->count($workspace, $definition);
        $connected = DB::table('channel_accounts')->where('workspace_id', $workspace->id)->where('status', 'active')->pluck('channel')->unique()->all();
        $channels = [];
        $cost = null;
        foreach (self::CHANNELS as $channel) {
            $consented = $total === 0 ? 0 : $this->consented($workspace, $definition, $channel)->count();
            $channels[$channel] = ['consented' => $consented, 'connected' => in_array($channel, $connected, true)];
        }
        if ($channels['whatsapp']['consented'] > 0) {
            $rows = $this->consented($workspace, $definition, 'whatsapp')
                ->selectRaw("UPPER(COALESCE(NULLIF(c.country, ''), ?)) AS country, COUNT(*) AS n", [$workspace->country ?: 'CO'])
                ->groupBy('country')->get();
            $usd = 0.0;
            foreach ($rows as $row) {
                $usd += (int) $row->n * (WaRateCard::rate((string) $row->country, 'marketing', $now) ?? 0.0);
            }
            $currency = (string) ($workspace->currency ?: 'USD');
            $rate = $this->fx->perUsd($currency, $now);
            $cost = ['usd' => round($usd, 4), 'local' => $rate !== null ? round($usd * $rate, 2) : null, 'currency' => $currency];
        }

        return ['total' => $total, 'channels' => $channels, 'whatsapp_cost' => $cost];
    }

    /** @param array<string, mixed> $definition */
    private function consented(Workspace $workspace, array $definition, string $channel): Builder
    {
        return $this->audience->query($workspace, $definition)
            ->whereExists(fn ($s) => $s->from('contact_consents as cr')->whereColumn('cr.contact_id', 'c.id')
                ->where('cr.workspace_id', $workspace->id)->where('cr.channel', $channel)
                ->where('cr.purpose', 'marketing')->where('cr.status', 'granted'));
    }
}
