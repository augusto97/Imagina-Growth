<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ventana de atención de una conversación (cumplimiento.md §5, M11):
 *
 * - `open`: menos de 24 h desde el último mensaje del cliente: se responde libre.
 * - `human_agent`: solo Messenger, entre 24 h y 7 días: una persona del equipo
 *   puede responder con la etiqueta `HUMAN_AGENT` (nunca la IA, nunca marketing).
 * - `closed`: fuera de todo eso. En WhatsApp queda la plantilla aprobada; en
 *   Messenger e Instagram no queda nada hasta que el cliente vuelva a escribir.
 */
final class ConversationWindow
{
    public const OPEN = 'open';

    public const HUMAN_AGENT = 'human_agent';

    public const CLOSED = 'closed';

    public const HUMAN_AGENT_DAYS = 7;

    /** Etiqueta de Messenger para la respuesta de una persona fuera de las 24 h [verificar]. */
    public const HUMAN_AGENT_TAG = 'HUMAN_AGENT';

    public static function state(Channel $channel, ?CarbonInterface $windowExpiresAt, ?CarbonInterface $lastInboundAt, ?CarbonInterface $at = null): string
    {
        $at = $at ?? CarbonImmutable::now();
        if ($windowExpiresAt !== null && $windowExpiresAt->greaterThan($at)) {
            return self::OPEN;
        }
        if ($channel === Channel::Messenger && $lastInboundAt !== null && $lastInboundAt->copy()->addDays(self::HUMAN_AGENT_DAYS)->greaterThan($at)) {
            return self::HUMAN_AGENT;
        }

        return self::CLOSED;
    }

    /** Estado de la conversación del contacto con la cuenta en el momento dado. */
    public static function forContact(string $workspaceId, string $contactId, ChannelAccount $account, CarbonInterface $at): string
    {
        $row = DB::table('conversations')->where('workspace_id', $workspaceId)->where('contact_id', $contactId)
            ->where('channel_account_id', $account->id)->first(['window_expires_at', 'last_inbound_at']);
        if ($row === null) {
            return self::CLOSED;
        }
        $parse = fn (mixed $v): ?CarbonImmutable => is_string($v) ? CarbonImmutable::parse($v, 'UTC') : null;

        return self::state($account->channel, $parse($row->window_expires_at), $parse($row->last_inbound_at), $at);
    }
}
