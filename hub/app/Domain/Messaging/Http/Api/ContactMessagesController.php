<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Api;

use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Messaging\Models\Message;
use App\Domain\Privacy\Actions\ExportContactData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** GET /api/w/{ws}/contacts/{contact}/messages — pestaña «Mensajes» del perfil 360. */
final class ContactMessagesController
{
    public function __invoke(Request $request, string $contact): JsonResponse
    {
        $model = ContactAccess::find($contact);
        $ids = ExportContactData::contactIds($model);
        $page = Message::query()->whereIn('contact_id', $ids)->orderByDesc('created_at')->paginate(25);
        $items = collect($page->items());
        $flows = DB::table('flows')->whereIn('id', $items->pluck('flow_id')->filter()->unique()->all())->pluck('name', 'id');
        $campaigns = DB::table('campaigns')->whereIn('id', $items->pluck('campaign_id')->filter()->unique()->all())->pluck('name', 'id');

        return response()->json([
            'data' => $items->map(function (Message $m) use ($flows, $campaigns) {
                $payload = $m->payload ?? [];
                $preview = $m->direction === 'in' ? null : ($payload['subject'] ?? ($payload['wa']['name'] ?? (isset($payload['text']) ? mb_substr((string) $payload['text'], 0, 120) : null)));

                return [
                    'id' => $m->publicId(),
                    'direction' => $m->direction,
                    'channel' => $m->channel->value,
                    'kind' => $m->kind->value,
                    'source_type' => $m->source_type,
                    'source_name' => $m->flow_id !== null ? ($flows[$m->flow_id] ?? null) : ($m->campaign_id !== null ? ($campaigns[$m->campaign_id] ?? null) : null),
                    'status' => $m->status,
                    'preview' => is_string($preview) ? $preview : null,
                    'button_payload' => $m->direction === 'in' ? ($payload['button_payload'] ?? null) : null,
                    'failed_reason' => $m->failed_reason,
                    'cost_estimated' => $m->cost_estimated,
                    'created_at' => $m->created_at?->toIso8601String(),
                    'sent_at' => $m->sent_at?->toIso8601String(),
                    'delivered_at' => $m->delivered_at?->toIso8601String(),
                    'read_at' => $m->read_at?->toIso8601String(),
                    'opened_at' => $m->opened_at?->toIso8601String(),
                    'clicked_at' => $m->clicked_at?->toIso8601String(),
                    'replied_at' => $m->replied_at?->toIso8601String(),
                ];
            })->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }
}
