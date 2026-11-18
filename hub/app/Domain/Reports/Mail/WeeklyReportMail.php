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
            'actionUrl' => $base.($this->facts['action']['to'] ?? ''),
            'dashboardUrl' => $base.'analitica',
        ]);
    }
}
