<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Enums;

/** Canales de mensajería (M07). */
enum Channel: string
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
}
