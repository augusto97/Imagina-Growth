<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Coexistence;

use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Channels\Data\BusinessAppMessage;
use App\Domain\Messaging\Inbound\InboundHandler;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Importa una parte del historial de la app de WhatsApp Business (`history`, coexistencia; hasta 180 días antes de
 * conectar, en fases y partes desordenadas): cada mensaje queda en la conversación del cliente, cifrado como
 * cualquier otro e idempotente por su ID de WhatsApp (una parte repetida no duplica nada).
 *
 * Es solo memoria: no dispara flujos ni eventos de mensajes, no pasa por el agente, la clasificación, los avisos ni el
 * push, no crea consentimiento y no abre la ventana de 24 h (Meta: un cliente que escribió antes de conectar solo
 * recibe plantillas). Lo único que se toma en cuenta es una baja escrita por el cliente (optOut). Las conversaciones que nacen aquí quedan cerradas: la bandeja abierta muestra solo lo que pide
 * respuesta. El avance (fase, `progress` de 0 a 100, mensajes copiados o si el negocio no lo compartió) queda en
 * `channel_accounts.config.history`.
 */
final class ImportBusinessAppHistory
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly BusinessAppContacts $contacts, private readonly RecordConsent $consents) {}

    /**
     * @param  array{phase: int|null, chunk: int|null, progress: int|null, declined: bool, messages: list<BusinessAppMessage>}  $chunk
     * @return int mensajes nuevos
     */
    public function handle(ChannelAccount $account, array $chunk): int
    {
        $workspace = Workspace::query()->find($account->workspace_id);
        if ($workspace === null) {
            return 0;
        }

        return $this->current->run($workspace, function () use ($workspace, $account, $chunk): int {
            $imported = 0;
            /** @var array<string, array{0: Contact, 1: Conversation}|null> $people */
            $people = [];
            foreach ($chunk['messages'] as $m) {
                if ($m->type === 'edit' || $m->type === 'revoke') {
                    BusinessAppMessages::amend($m);

                    continue;
                }
                if (BusinessAppMessages::known($m->providerMessageId)) {
                    continue;
                }
                $who = ($m->phone ?? '').'|'.($m->userId ?? '');
                if (! array_key_exists($who, $people)) {
                    $resolved = $this->contacts->resolve($workspace, $m->phone, $m->userId);
                    $people[$who] = $resolved === null ? null : [$resolved[0], $this->conversation($workspace, $account, $resolved[0])];
                }
                if ($people[$who] === null) {
                    continue;
                }
                [$contact, $conversation] = $people[$who];
                if (BusinessAppMessages::store($workspace, $account, $contact, $conversation, $m, BusinessAppMessages::HISTORY) === null) {
                    continue;
                }
                $imported++;
                if ($conversation->last_message_at === null || $m->at->greaterThan($conversation->last_message_at)) {
                    $conversation->last_message_at = $m->at;
                }
                $this->optOut($workspace, $contact, $m);
            }
            foreach ($people as $pair) {
                if ($pair !== null && $pair[1]->isDirty()) {
                    $pair[1]->save();
                }
            }
            BusinessAppMessages::state($account, 'history', fn (array $s): array => [
                'phase' => max((int) ($s['phase'] ?? 0), (int) $chunk['phase']),
                'progress' => min(100, max((int) ($s['progress'] ?? 0), (int) $chunk['progress'])),
                'messages' => (int) ($s['messages'] ?? 0) + $imported,
                'declined' => (bool) ($s['declined'] ?? false) || $chunk['declined'],
                'updated_at' => now()->toIso8601String(),
            ]);

            return $imported;
        });
    }

    /** Los adjuntos de las dos últimas semanas del historial, que Meta manda después de los marcadores. */
    public function media(ChannelAccount $account, BusinessAppMessage $m): void
    {
        $workspace = Workspace::query()->find($account->workspace_id);
        if ($workspace !== null) {
            $this->current->run($workspace, fn () => BusinessAppMessages::attachLater($workspace, $m));
        }
    }

    /**
     * Una baja escrita en la app antes de conectar («baja», «no me escriban»…) se respeta (consentimiento primero):
     * se registra como revocación del marketing de WhatsApp con la fecha del mensaje y sin respuesta de confirmación.
     * Como no es autoritativa, un consentimiento dado después (p. ej. en el checkout) la deja atrás.
     */
    private function optOut(Workspace $workspace, Contact $contact, BusinessAppMessage $m): void
    {
        if ($m->outbound || InboundHandler::unsubscribeKeyword($m->text) === null) {
            return;
        }
        $this->consents->handle($workspace, $contact->id, 'whatsapp', 'marketing', 'revoked', 'whatsapp_history', null, $m->at, ['via' => 'keyword', 'provider_message_id' => $m->providerMessageId]);
    }

    private function conversation(Workspace $workspace, ChannelAccount $account, Contact $contact): Conversation
    {
        $conversation = Conversation::query()->firstOrNew(['channel_account_id' => $account->id, 'contact_id' => $contact->id]);
        if (! $conversation->exists) {
            $conversation->fill(['workspace_id' => $workspace->id, 'status' => 'closed'])->save();
        }

        return $conversation;
    }
}
