<?php

declare(strict_types=1);

namespace App\Domain\Billing\Mail;

use App\Domain\Workspaces\Data\BrandingData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class UsageAlertMail extends Mailable
{
    use BrandedMail, Queueable;

    public function __construct(
        public string $workspaceName, public string $metric, public int $value, public int $limit, public int $threshold,
        public BrandingData $brand, public string $url,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->threshold >= 100
            ? __(':workspace llegó al 100 % de :metric de su plan', ['workspace' => $this->workspaceName, 'metric' => __('billing.metric.'.$this->metric)])
            : __(':workspace va en el :pct % de :metric de su plan', ['workspace' => $this->workspaceName, 'pct' => $this->threshold, 'metric' => __('billing.metric.'.$this->metric)]);

        return new Envelope(from: $this->brandFrom($this->brand), subject: $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'billing.mail.usage-alert');
    }
}
