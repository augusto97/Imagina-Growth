<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Actions;

use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Support\CreatorToken;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Str;

/**
 * Enlace mágico del panel del creador: un nonce nuevo (los enlaces anteriores dejan
 * de servir) y, si el afiliado tiene correo, el aviso transaccional por el canal de
 * correo del espacio de trabajo. Devuelve el enlace para que el equipo también
 * pueda copiarlo y mandarlo por otro medio.
 */
final class SendCreatorPortalLink
{
    public function __construct(private readonly MessageDispatcher $dispatcher, private readonly CurrentWorkspace $current) {}

    /** @return array{url: string, sent: bool, reason: string|null} */
    public function handle(Affiliate $affiliate, bool $email = true): array
    {
        $nonce = Str::lower(Str::random(16));
        $affiliate->forceFill(['portal_nonce' => $nonce, 'portal_sent_at' => now()])->save();
        $url = CreatorToken::url(CreatorToken::make($affiliate->workspace_id, $affiliate->id, $nonce));
        if (! $email || $affiliate->email === null || $affiliate->email === '') {
            return ['url' => $url, 'sent' => false, 'reason' => $email ? 'no_email' : null];
        }
        $workspace = $this->current->get();
        $subject = __('Tu panel de creador en :brand', ['brand' => $workspace->name]);
        $html = view('referrals.creator-link-mail', ['brand' => $workspace->name, 'name' => $affiliate->name, 'url' => $url, 'days' => CreatorToken::TTL_DAYS])->render();
        $text = __("Hola, :name:\n\nEste es tu enlace para ver tus ventas y comisiones en :brand (vale :days días):\n:url", ['name' => $affiliate->name, 'brand' => $workspace->name, 'days' => CreatorToken::TTL_DAYS, 'url' => $url]);

        $result = $this->dispatcher->dispatch(new OutboundMessage(
            workspaceId: $workspace->id,
            contactId: null,
            channel: Channel::Email,
            kind: MessageKind::Transactional,
            sourceType: 'system',
            sourceId: $affiliate->publicId(),
            context: ['subject' => $subject, 'html' => $html, 'text' => $text],
            idempotencyKey: 'creator-portal:'.$affiliate->id.':'.$nonce,
            to: $affiliate->email,
        ));

        return ['url' => $url, 'sent' => $result->sent(), 'reason' => $result->sent() ? null : ($result->reason ?? $result->status)];
    }
}
