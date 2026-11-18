<?php

declare(strict_types=1);

namespace App\Domain\Flows\Support;

use App\Domain\Flows\Models\Flow;

/**
 * Secretos derivados por flujo (no se guardan): la firma de los webhooks
 * salientes y el token del webhook de entrada.
 */
final class FlowSecrets
{
    public static function webhookSecret(Flow $flow): string
    {
        return 'whsec_'.substr(hash_hmac('sha256', 'flow-webhook-out:'.$flow->id, (string) config('app.key')), 0, 40);
    }

    /** Token del endpoint público `/webhooks/flows/{token}` (disparador de webhook). */
    public static function inboundToken(Flow $flow): string
    {
        return $flow->publicId().'.'.substr(hash_hmac('sha256', 'flow-webhook-in:'.$flow->id, (string) config('app.key')), 0, 32);
    }

    public static function verifyInbound(string $token): ?string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $flow = Flow::withoutWorkspaceScope()->find(substr($parts[0], strlen(Flow::ID_PREFIX) + 1));
        if (! $flow instanceof Flow) {
            return null;
        }

        return hash_equals(self::inboundToken($flow), $token) ? $flow->id : null;
    }
}
