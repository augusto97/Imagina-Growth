<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Drivers;

use App\Domain\Messaging\Channels\ChannelDriver;
use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Channels\Data\SendResult;
use App\Domain\Messaging\Channels\Data\WebhookBatch;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Support\EmailBuilder;
use Illuminate\Http\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * SMTP genérico (alternativa a SES). Sin webhooks: solo se sabe que el servidor
 * aceptó el correo; los rebotes llegan al buzón del remitente.
 */
class SmtpDriver implements ChannelDriver
{
    public function send(ChannelAccount $account, OutgoingMessage $message): SendResult
    {
        $email = EmailBuilder::build($account, $message);
        try {
            $sent = $this->transport($account)->send($email);
        } catch (TransportExceptionInterface $e) {
            return SendResult::failed($e->getMessage(), 'smtp', true);
        }

        return SendResult::sent($sent?->getMessageId() ?? $email->getHeaders()->get('Message-ID')?->getBodyAsString());
    }

    protected function transport(ChannelAccount $account): TransportInterface
    {
        $transport = new EsmtpTransport(
            (string) $account->credential('host'),
            (int) ($account->credential('port') ?? 587),
            $account->credential('encryption') === 'ssl' ? true : null,
        );
        if (($user = $account->credential('username')) !== null) {
            $transport->setUsername($user);
            $transport->setPassword((string) $account->credential('password'));
        }

        return $transport;
    }

    public function handleWebhook(Request $request, ?ChannelAccount $account): WebhookBatch
    {
        return new WebhookBatch;
    }

    public function syncTemplates(ChannelAccount $account): int
    {
        return 0;
    }

    public function estimateCost(ChannelAccount $account, string $country, MessageKind $kind): ?float
    {
        return null;
    }

    public function capabilities(): array
    {
        return ['marketing' => true, 'templates' => false, 'free_text' => true, 'media' => true, 'buttons' => true, 'rate_per_second' => (int) config('igr-messaging.smtp.rate_per_second', 5)];
    }
}
