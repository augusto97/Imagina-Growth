<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Public;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Support\ContactLinkToken;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;

/** Lo que identifica un token de baja o de preferencias, ya verificado. */
final class ContactLinkContext
{
    private function __construct(
        public readonly Workspace $workspace,
        public readonly Contact $contact,
        public readonly string $channel,
        public readonly ?Message $message,
    ) {}

    public static function fromToken(string $token): ?self
    {
        $data = ContactLinkToken::parse($token);
        $workspace = $data !== null ? Workspace::query()->find($data['w']) : null;
        if ($data === null || $workspace === null) {
            return null;
        }
        $contact = Contact::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereKey($data['c'])->first();
        if ($contact === null) {
            return null;
        }
        // Un contacto fusionado: la baja va al que quedó.
        for ($i = 0; $i < 10 && $contact->getAttribute('merged_into_id') !== null; $i++) {
            $next = Contact::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereKey($contact->getAttribute('merged_into_id'))->first();
            if ($next === null) {
                break;
            }
            $contact = $next;
        }
        $messageId = $data['m'] !== null ? PrefixedId::decode(Message::ID_PREFIX, $data['m']) : null;
        $message = $messageId !== null ? Message::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereKey($messageId)->first() : null;

        return new self($workspace, $contact, $data['ch'], $message);
    }
}
