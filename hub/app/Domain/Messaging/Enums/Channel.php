<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Enums;

/** Canales de mensajería (M07). Messenger e Instagram (Fase 5) solo conversan desde la bandeja: nunca marketing. */
enum Channel: string
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
    case Messenger = 'messenger';
    case Instagram = 'instagram';

    /** Canales de Meta por mensaje directo: identidad por página (PSID/IGSID), ventana de 24 h y sin marketing (M11). */
    public function isSocial(): bool
    {
        return $this === self::Messenger || $this === self::Instagram;
    }

    /** Canales con conversación en la bandeja y ventana de atención de 24 h. */
    public function isConversational(): bool
    {
        return $this === self::WhatsApp || $this->isSocial();
    }
}
