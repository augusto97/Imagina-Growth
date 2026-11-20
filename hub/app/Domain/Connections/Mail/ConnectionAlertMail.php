<?php

declare(strict_types=1);

namespace App\Domain\Connections\Mail;

use App\Domain\Billing\Mail\BrandedMail;
use App\Domain\Workspaces\Data\BrandingData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso a dueños y administradores de una conexión por vencer (7 días y 1 día antes), vencida o inválida, o de una
 * cuenta de canal que Meta desconectó (ConnectionAlerts). Sale por el correo de la plataforma con la marca del
 * cliente, no por los canales del cliente: si el problema es justo su canal, el aviso igual llega.
 */
final class ConnectionAlertMail extends Mailable
{
    use BrandedMail, Queueable;

    public function __construct(
        public string $workspaceName,
        public string $state,                 // expires_7d | expires_1d | expired | disconnected (ConnectionAlerts)
        public string $provider,
        public ?string $account,
        public ?string $expiresOn,
        public ?string $detail,
        public BrandingData $brand,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        $vars = ['provider' => $this->provider, 'workspace' => $this->workspaceName, 'date' => (string) $this->expiresOn, 'account' => (string) $this->account];

        return new Envelope(from: $this->brandFrom($this->brand), subject: match ($this->state) {
            'expires_7d' => __('La conexión con :provider de :workspace vence el :date', $vars),
            'expires_1d' => __('La conexión con :provider de :workspace vence mañana', $vars),
            'disconnected' => __('Tu :provider :account se desconectó de :workspace', $vars),
            default => __('Vuelve a conectar :provider en :workspace', $vars),
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'connections.mail.alert');
    }
}
