<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Drivers;

use App\Domain\Messaging\Channels\ChannelCapabilities;
use App\Domain\Messaging\Channels\ChannelDriver;
use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Channels\Data\SendResult;
use App\Domain\Messaging\Channels\Data\WebhookBatch;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Notices\Mail\PlatformTransactionalMail;
use App\Domain\Notices\Support\PlatformMail;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Correo transaccional del negocio por el correo de la plataforma (lote 0.4.0, `PlatformMail`): el respaldo cuando
 * el negocio no conectó su correo. Usa el mailer de la plataforma (Plataforma → Ajustes → Correo) con el nombre del
 * negocio como remitente visible y su correo de contacto en «Responder a». Sin webhooks: solo se sabe que el servidor
 * lo aceptó. Nunca lleva marketing (el despachador solo lo usa para transaccional y utilidad).
 */
final class PlatformMailDriver implements ChannelDriver
{
    public function send(ChannelAccount $account, OutgoingMessage $message): SendResult
    {
        $workspace = Workspace::query()->find($account->workspace_id);
        if ($workspace === null || ! PlatformMail::available()) {
            return SendResult::failed(__('El correo de la plataforma no está configurado.'), 'platform_mail_off', false);
        }
        $mail = new PlatformTransactionalMail(
            subjectLine: (string) ($message->subject ?? ''),
            htmlBody: $message->html,
            textBody: $message->text,
            fromName: PlatformMail::fromName($workspace),
            replyToAddress: PlatformMail::replyTo($workspace),
            reference: $message->messageId,
        );
        try {
            $sent = Mail::to($message->to)->send($mail);
        } catch (TransportExceptionInterface $e) {
            return SendResult::failed(mb_substr($e->getMessage(), 0, 300), 'platform_mail', true);
        }

        return SendResult::sent($sent instanceof SentMessage ? $sent->getMessageId() : null);
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

    /** Solo transaccional y de utilidad (nunca marketing), sin adjuntos. */
    public function capabilities(): ChannelCapabilities
    {
        return ChannelCapabilities::basic(false, 5, 100000);
    }
}
