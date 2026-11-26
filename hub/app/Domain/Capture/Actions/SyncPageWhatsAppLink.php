<?php

declare(strict_types=1);

namespace App\Domain\Capture\Actions;

use App\Domain\Capture\Models\LandingPage;
use App\Domain\Capture\Models\WhatsAppLink;
use App\Domain\Capture\Support\WaCodes;

/**
 * El botón de WhatsApp de una página de captura es un chat con atribución (`kind = page`): se crea la primera vez
 * que se enciende, conserva su código (los chats que ya llegaron siguen atribuidos) y su mensaje sigue al de la
 * página. Apagar el botón no borra el enlace; archivar la página lo archiva.
 */
final class SyncPageWhatsAppLink
{
    public function handle(LandingPage $page, ?string $userId = null): ?WhatsAppLink
    {
        $wa = (array) (($page->content ?? [])['whatsapp'] ?? []);
        $link = $page->wa_link_id !== null ? WhatsAppLink::query()->find($page->wa_link_id) : null;
        if ($page->status === 'archived') {
            $link?->forceFill(['status' => 'archived'])->save();

            return $link;
        }
        if (! (bool) ($wa['enabled'] ?? false)) {
            return $link;
        }
        $message = is_string($wa['message'] ?? null) && trim($wa['message']) !== '' ? trim($wa['message']) : __('Hola, quiero más información');
        $name = mb_substr(__('Botón de «:page»', ['page' => $page->name]), 0, 120);
        if ($link === null) {
            $link = WhatsAppLink::query()->create([
                'workspace_id' => $page->workspace_id,
                'page_id' => $page->id,
                'kind' => 'page',
                'name' => $name,
                'code' => WaCodes::generate('page'),
                'message' => mb_substr($message, 0, 300),
                'settings' => ['create_lead' => true, 'ask_consent' => true],
                'created_by' => $userId,
            ]);
            $page->forceFill(['wa_link_id' => $link->id])->save();

            return $link;
        }
        $link->forceFill(['message' => mb_substr($message, 0, 300), 'name' => $name, 'status' => 'active'])->save();

        return $link;
    }
}
