<?php

declare(strict_types=1);

namespace App\Domain\Reports\Mail;

use App\Domain\Intelligence\Insights\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Resumen semanal al dueño: 5 cifras, 3 hallazgos y 1 acción (M05). */
final class WeeklyReportMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $facts */
    public function __construct(public array $facts, public string $recipientName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Tu semana en :workspace: :revenue en ventas', [
            'workspace' => $this->facts['workspace']['name'],
            'revenue' => Money::format($this->facts['numbers'][0]['value'] ?? 0, $this->facts['workspace']['currency']),
        ]));
    }

    public function content(): Content
    {
        $currency = (string) $this->facts['workspace']['currency'];
        $numbers = array_map(function (array $n) use ($currency): array {
            $value = match ($n['metric']) {
                'revenue', 'aov' => Money::format($n['value'], $currency),
                'conversion_rate' => Money::percent((float) $n['value'], 2),
                default => Money::number($n['value']),
            };
            $change = $n['change'] === null ? null : ($n['change'] >= 0 ? '▲ ' : '▼ ').Money::percent(abs((float) $n['change']));

            return ['label' => __('report.metric.'.$n['metric']), 'value' => $value, 'change' => $change, 'up' => ($n['change'] ?? 0) >= 0];
        }, $this->facts['numbers']);

        $base = rtrim((string) config('igr.urls.app'), '/').'/w/'.$this->facts['workspace']['id'].'/';

        return new Content(markdown: 'mail.weekly-report', with: [
            'name' => $this->recipientName,
            'workspace' => $this->facts['workspace']['name'],
            'period' => $this->facts['period'],
            'numbers' => $numbers,
            'insights' => array_map(fn (array $i) => $i + ['impact_text' => $i['impact'] !== null ? Money::format($i['impact'], $currency) : null], $this->facts['insights']),
            'action' => $this->facts['action'],
            'analysis' => isset($this->facts['analysis']) ? [
                'body' => (string) $this->facts['analysis']['body'],
                'ai' => ($this->facts['analysis']['source'] ?? '') === 'ai',
                'actions' => array_map(fn (array $a) => ['title' => (string) ($a['titulo'] ?? ''), 'detail' => (string) ($a['detalle'] ?? ''), 'url' => $base.($a['ruta'] ?? 'hallazgos')], (array) $this->facts['analysis']['actions']),
            ] : null,
            'leads' => array_map(fn (array $f) => self::leadFunnel($f, $currency), (array) ($this->facts['leads'] ?? [])),
            'leadsUrl' => $base.'leads?tab=indicadores',
            'todayUrl' => $base.'hoy',
            'actionUrl' => $base.($this->facts['action']['to'] ?? ''),
            'dashboardUrl' => $base.'analitica',
        ]);
    }

    /**
     * Un embudo de leads listo para la tabla del correo: etapas con lo de la semana, la anterior
     * y el paso entre etapas; dónde se cae; perdidos y estancados; la conclusión.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private static function leadFunnel(array $f, string $currency): array
    {
        $pct = fn (mixed $r): ?string => is_numeric($r) ? Money::percent((float) $r, 0) : null;

        return [
            'name' => (string) ($f['pipeline']['name'] ?? ''),
            'stages' => array_map(fn (array $s) => [
                'label' => (string) $s['label'], 'reached' => Money::number((int) $s['reached']), 'previous' => Money::number((int) $s['previous']),
                'conversion' => $pct($s['conversion']), 'previous_conversion' => $pct($s['previous_conversion']),
            ], (array) ($f['stages'] ?? [])),
            'drop' => is_array($f['drop'] ?? null) ? ['from' => (string) $f['drop']['from_label'], 'to' => (string) $f['drop']['to_label'], 'rate' => $pct($f['drop']['conversion'])] : null,
            'lost' => (int) ($f['lost']['count'] ?? 0),
            'reasons' => array_map(fn (array $r) => ['reason' => (string) ($r['reason'] ?? __('Sin motivo')), 'count' => (int) $r['count']], array_slice((array) ($f['lost']['reasons'] ?? []), 0, 3)),
            'stalled' => array_map(fn (array $s) => ['label' => (string) $s['label'], 'count' => (int) $s['count']], (array) ($f['stalled'] ?? [])),
            'stale_days' => (int) ($f['stale_days'] ?? 7),
            'won' => (int) ($f['won']['week']['count'] ?? 0),
            'won_value' => Money::format((float) ($f['won']['week']['value'] ?? 0), $currency),
            'conclusion' => (string) ($f['conclusion']['text'] ?? ''),
            'ai' => ($f['conclusion']['source'] ?? '') === 'ai',
        ];
    }
}
