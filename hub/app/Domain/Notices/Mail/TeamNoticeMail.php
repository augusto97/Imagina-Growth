<?php

declare(strict_types=1);

namespace App\Domain\Notices\Mail;

use App\Domain\Billing\Mail\BrandedMail;
use App\Domain\Workspaces\Data\BrandingData;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso a una persona del equipo (lote 0.4.0, `NotifyTeam`): nueva cita, cita movida o cancelada. Sale por el correo de
 * la plataforma con la marca del cliente, no por los canales del negocio: llega aunque el negocio no haya conectado su
 * correo.
 */
final class TeamNoticeMail extends Mailable
{
    use BrandedMail;

    public function __construct(
        public string $workspaceName,
        public string $title,
        public ?string $body,
        public string $url,
        public BrandingData $brand,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: $this->brandFrom($this->brand), subject: $this->title.' · '.$this->workspaceName);
    }

    public function content(): Content
    {
        return new Content(markdown: 'notices.mail.team');
    }
}
