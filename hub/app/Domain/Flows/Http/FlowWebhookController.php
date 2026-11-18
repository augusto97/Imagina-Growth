<?php

declare(strict_types=1);

namespace App\Domain\Flows\Http;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Actions\EnrollContacts;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Support\EventProps;
use App\Domain\Flows\Support\FlowSecrets;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /webhooks/flows/{token}` con `{email | phone | contact_id, data?}`:
 * hace entrar a un contacto que ya existe a un flujo con disparador de webhook.
 * No crea contactos (eso es la API pública, Fase 5); `data` llega a las
 * plantillas sin datos personales.
 */
final class FlowWebhookController
{
    public function __invoke(Request $request, string $token, CurrentWorkspace $current, EnrollContacts $enroll): JsonResponse
    {
        $flowId = FlowSecrets::verifyInbound($token);
        $flow = $flowId === null ? null : Flow::withoutWorkspaceScope()->find($flowId);
        if (! $flow instanceof Flow || ($flow->trigger['type'] ?? null) !== 'webhook') {
            return response()->json(['ok' => false, 'error' => ['code' => 'not_found']], 404);
        }
        $workspace = Workspace::query()->findOrFail($flow->workspace_id);
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_id' => ['nullable', 'string', 'max:40'],
            'data' => ['nullable', 'array'],
        ]);

        return $current->run($workspace, function () use ($flow, $workspace, $data, $enroll) {
            if (! $flow->isActive()) {
                return response()->json(['ok' => false, 'error' => ['code' => 'flow_inactive']], 409);
            }
            $contact = null;
            if (isset($data['contact_id'])) {
                $contact = Contact::query()->find(PrefixedId::decode(Contact::ID_PREFIX, (string) $data['contact_id']) ?? '-');
            } elseif (isset($data['email'])) {
                $contact = Contact::query()->where('email_bidx', BlindIndex::email($workspace->id, (string) $data['email']))->first();
            } elseif (isset($data['phone'])) {
                $e164 = Phone::toE164((string) $data['phone'], $workspace->country ?: 'CO');
                $contact = $e164 === null ? null : Contact::query()->where('phone_bidx', BlindIndex::phone($workspace->id, $e164))->first();
            }
            if ($contact === null) {
                return response()->json(['ok' => false, 'error' => ['code' => 'contact_not_found']], 404);
            }
            $entered = $enroll->handle($flow, [$contact->id], 'webhook', EventProps::sanitize((array) ($data['data'] ?? [])));

            return response()->json(['ok' => true, 'entered' => $entered === 1], 202);
        });
    }
}
