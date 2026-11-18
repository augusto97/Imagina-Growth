<?php

declare(strict_types=1);

namespace App\Domain\Billing\Mail;

use App\Domain\Workspaces\Data\BrandingData;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class BillingNoticeMail extends Mailable
{
    use BrandedMail, Queueable;

    public function __construct(
        public string $accountName, public string $status, public bool $overdue, public ?CarbonImmutable $graceEnds,
        public BrandingData $brand, public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: $this->brandFrom($this->brand), subject: $this->status === 'suspended'
            ? __('Pausamos el marketing de :account', ['account' => $this->accountName])
            : __(':account está en periodo de gracia', ['account' => $this->accountName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'billing.mail.notice');
    }
}
