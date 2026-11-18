<?php

declare(strict_types=1);

namespace App\Domain\Agency\Mail;

use App\Domain\Agency\Models\MonthlyReport;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Data\BrandingData;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * El reporte mensual al dueño, con el PDF adjunto y la marca de la agencia: el
 * remitente propio solo si su dominio está verificado (ver AgencyBranding).
 */
final class MonthlyReportMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $workspaceId, public string $reportId, public string $recipientName) {}

    public function envelope(): Envelope
    {
        [$workspace, $report, $brand] = $this->load();
        $facts = (array) $report->facts;

        return new Envelope(
            from: new Address($brand->senderEmail, $brand->senderName),
            replyTo: $brand->supportEmail ? [new Address($brand->supportEmail, $brand->name)] : [],
            subject: __(':business · Reporte de :month: :amount generados', [
                'business' => $workspace->name,
                'month' => (string) ($facts['mes'] ?? ''),
                'amount' => Money::format($facts['dinero_generado']['total'] ?? 0, (string) ($facts['moneda'] ?? 'COP')),
            ]),
        );
    }

    public function content(): Content
    {
        [$workspace, $report, $brand] = $this->load();
        $facts = (array) $report->facts;
        $base = rtrim($brand->panelUrl ?: (string) config('igr.urls.app'), '/');

        return new Content(view: 'agency.mail.monthly-report', with: [
            'name' => $this->recipientName,
            'business' => $workspace->name,
            'month' => (string) ($facts['mes'] ?? ''),
            'amount' => Money::format($facts['dinero_generado']['total'] ?? 0, (string) ($facts['moneda'] ?? 'COP')),
            'summary' => (string) $report->summary,
            'brand' => $brand,
            'url' => $base.'/w/'.$workspace->publicId().'/reportes',
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        [, $report] = $this->load();
        if (! is_string($report->pdf_path)) {
            return [];
        }

        return [Attachment::fromStorageDisk('local', $report->pdf_path)->as('reporte-'.$report->period->format('Y-m').'.pdf')->withMime('application/pdf')];
    }

    /** @return array{0: Workspace, 1: MonthlyReport, 2: BrandingData} */
    private function load(): array
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $report = MonthlyReport::withoutWorkspaceScope()->where('workspace_id', $this->workspaceId)->findOrFail($this->reportId);

        return [$workspace, $report, app(Branding::class)->for($workspace)];
    }
}
