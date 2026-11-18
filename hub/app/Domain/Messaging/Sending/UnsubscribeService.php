<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sending;

use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Support\MessageEvents;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Baja de marketing (cumplimiento.md §4 y §8): revoca el consentimiento del
 * canal (o de todos) al instante, como acción de la persona (gana siempre), y
 * emite message.unsubscribed si llegó desde un mensaje. SendPolicy la ve en el
 * siguiente envío: se aplica en todo el sistema en menos de 1 minuto.
 */
final class UnsubscribeService
{
    public function __construct(private readonly RecordConsent $consents, private readonly MessageEvents $events) {}

    /**
     * @param  'channel'|'all'  $scope
     * @param  array<string, mixed>  $evidence
     * @return list<string> canales revocados
     */
    public function unsubscribe(Workspace $workspace, string $contactId, string $channel, string $scope, string $via, ?Message $message = null, array $evidence = []): array
    {
        $channels = $scope === 'all' ? ['email', 'whatsapp', 'sms'] : [$channel];
        $revoked = [];
        $now = CarbonImmutable::now();
        foreach ($channels as $ch) {
            if (! in_array($ch, ContactConsent::CHANNELS, true)) {
                continue;
            }
            $changed = $this->consents->handle($workspace, $contactId, $ch, 'marketing', 'revoked', $via === 'keyword' ? 'whatsapp_reply' : 'unsubscribe', null, $now, $evidence + ['via' => $via, 'message_id' => $message?->publicId()], true);
            if ($changed) {
                $revoked[] = $ch;
            }
        }
        if ($message !== null) {
            $this->events->emit($workspace, $message, 'message.unsubscribed', ['scope' => $scope, 'via' => $via]);
        }

        return $revoked;
    }
}
