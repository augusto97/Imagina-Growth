<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Models\ChannelAccount;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Correo MIME con las cabeceras que piden Gmail y Yahoo (cumplimiento.md §8):
 * List-Unsubscribe con URL https y List-Unsubscribe-Post de un clic (RFC 8058)
 * en el marketing, y un Message-ID propio para seguir el mensaje.
 */
final class EmailBuilder
{
    public static function build(ChannelAccount $account, OutgoingMessage $message): Email
    {
        $fromEmail = (string) ($account->credential('from_email') ?? 'hola@'.($account->credential('domain') ?? 'example.com'));
        $domain = substr((string) strrchr($fromEmail, '@'), 1) ?: 'example.com';
        $email = (new Email)
            ->from(new Address($fromEmail, (string) ($account->credential('from_name') ?? $account->display_name)))
            ->to($message->to)
            ->subject((string) $message->subject);
        if ($message->html !== null) {
            $email->html($message->html);
        }
        $email->text($message->text ?? strip_tags((string) $message->html));
        if (($reply = $account->credential('reply_to')) !== null) {
            $email->replyTo($reply);
        }
        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', strtolower($message->messageId).'@'.$domain);
        $headers->addTextHeader('X-IGR-Message', $message->messageId);
        foreach ($message->headers as $name => $value) {
            $headers->addTextHeader($name, $value);
        }

        return $email;
    }
}
