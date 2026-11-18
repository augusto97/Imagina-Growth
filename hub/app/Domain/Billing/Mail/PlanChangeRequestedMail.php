<?php

declare(strict_types=1);

namespace App\Domain\Billing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso interno al equipo de la plataforma. */
final class PlanChangeRequestedMail extends Mailable
{
    use Queueable;

    public function __construct(public string $accountName, public ?string $fromPlan, public string $toPlan, public ?string $note, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':account pide cambiar al plan :plan', ['account' => $this->accountName, 'plan' => $this->toPlan]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'billing.mail.plan-request');
    }
}
