<?php

declare(strict_types=1);

namespace App\Domain\Billing\Mail;

use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Workspaces\Data\BrandingData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

final class InvoiceMail extends Mailable
{
    use BrandedMail, Queueable;

    public function __construct(public BillingInvoice $invoice, public string $accountName, public BrandingData $brand, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: $this->brandFrom($this->brand), subject: __('Factura :number de :brand', ['number' => $this->invoice->number, 'brand' => $this->brand->name]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'billing.mail.invoice');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $path = $this->invoice->pdf_path;

        return $path !== null && Storage::disk('local')->exists($path)
            ? [Attachment::fromStorageDisk('local', $path)->as($this->invoice->number.'.pdf')->withMime('application/pdf')]
            : [];
    }
}
