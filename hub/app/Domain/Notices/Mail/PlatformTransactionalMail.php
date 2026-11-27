<?php

declare(strict_types=1);

namespace App\Domain\Notices\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Un correo transaccional del negocio a su cliente que sale por el correo de la plataforma (PlatformMailDriver, lote
 * 0.4.0): el contenido ya viene armado por el despachador (asunto, HTML y texto); el remitente visible es el negocio y
 * las respuestas van a su correo de contacto.
 */
final class PlatformTransactionalMail extends Mailable
{
    /** @param array<string, string> $extraHeaders */
    public function __construct(
        public string $subjectLine,
        public ?string $htmlBody,
        public ?string $textBody,
        public string $fromName,
        public ?string $replyToAddress,
        public string $reference,
        public array $extraHeaders = [],
    ) {}

    public function envelope(): Envelope
    {
        $from = (string) config('mail.from.address');

        return new Envelope(
            from: filter_var($from, FILTER_VALIDATE_EMAIL) !== false ? new Address($from, $this->fromName) : null,
            replyTo: $this->replyToAddress !== null ? [new Address($this->replyToAddress, $this->fromName)] : [],
            subject: $this->subjectLine,
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-IGR-Message' => $this->reference] + $this->extraHeaders);
    }

    public function content(): Content
    {
        $text = $this->textBody ?? trim(strip_tags((string) $this->htmlBody));

        return new Content(
            htmlString: $this->htmlBody ?? nl2br(e($text)),
            text: 'notices.mail.plain',
            with: ['body' => $text],
        );
    }
}
