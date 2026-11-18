<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Http;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Privacy\Actions\CreateDataRequest;
use App\Domain\Sites\Models\Site;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/s/privacy/requests (api-connector.md §5.4): los exporters y erasers
 * de WordPress crean la solicitud equivalente en el hub. La respuesta no dice
 * si la persona existe.
 */
final class SitePrivacyController
{
    public function __invoke(Request $request, CurrentWorkspace $current, CreateDataRequest $create): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:access,delete'],
            'email' => ['required', 'email', 'max:254'],
            'wp_request_id' => ['nullable', 'integer'],
        ]);
        /** @var Site $site */
        $site = $request->attributes->get('igr_site');
        $workspace = $current->get();
        $contactId = Contact::query()->where('email_bidx', BlindIndex::email($workspace->id, (string) $data['email']))->whereNull('merged_into_id')->value('id');
        $dataRequest = $create->handle($workspace, is_string($contactId) ? $contactId : null, $data['kind'], BlindIndex::normalizeEmail((string) $data['email']), 'wordpress', null, [
            'site' => $site->publicId(), 'wp_request_id' => $data['wp_request_id'] ?? null,
        ]);

        return response()->json(['request_id' => $dataRequest->publicId(), 'status' => $dataRequest->status, 'due_at' => $dataRequest->due_at?->toIso8601String()], 202);
    }
}
