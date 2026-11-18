<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa;

use App\Domain\Contacts\Models\Contact;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lista mínima de contactos de la Fase 0. El perfil 360 completo llega en la
 * Fase 1 (M03). Aplica ya la regla de M01: un `agent` solo ve los contactos
 * que tiene asignados (por conversación, Fase 4); hoy no tiene ninguno.
 */
final class ContactController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /api/w/{workspace}/contacts?q= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewAssignedContacts');
        $query = Contact::query()->whereNull('merged_into_id')->orderByDesc('last_seen_at');
        $this->scopeForRole($query);

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $wsId = $this->current->id();
            $query->where(function (Builder $w) use ($q, $wsId): void {
                $w->where('email_bidx', BlindIndex::email($wsId, $q));
                $digits = preg_replace('/[^0-9+]/', '', $q) ?? '';
                if (strlen($digits) >= 7) {
                    $e164 = Phone::toE164($digits, $this->current->get()->country);
                    if ($e164 !== null) {
                        $w->orWhere('phone_bidx', BlindIndex::phone($wsId, $e164));
                    }
                }
                $word = mb_strtolower(explode(' ', $q)[0]);
                if (mb_strlen($word) >= 2) {
                    $prefix = substr(BlindIndex::value($wsId, 'name', mb_substr($word, 0, 6)), 0, 16);
                    $w->orWhereJsonContains('name_prefix_bidx', $prefix);
                }
            });
        }

        $page = $query->paginate(min(100, max(10, (int) $request->query('per_page', 25))));
        $unmasked = Gate::allows('ws.exportContacts');

        return response()->json([
            'data' => collect($page->items())->map(fn (Contact $c) => [
                'id' => $c->publicId(),
                'name' => $c->displayName(),
                'email' => $c->email === null ? null : ($unmasked ? $c->email : Pii::maskEmail($c->email)),
                'phone' => $c->phone === null ? null : ($unmasked ? $c->phone : Pii::maskPhone($c->phone)),
                'city' => $c->getAttribute('city'),
                'lifecycle_stage' => $c->getAttribute('lifecycle_stage'),
                'orders_count' => (int) $c->getAttribute('orders_count'),
                'revenue_total_ws' => (string) $c->getAttribute('revenue_total_ws'),
                'last_order_at' => $c->getAttribute('last_order_at')?->toIso8601String(),
                'last_seen_at' => $c->getAttribute('last_seen_at')?->toIso8601String(),
            ]),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ]);
    }

    /** @param Builder<Contact> $query */
    private function scopeForRole(Builder $query): void
    {
        if (Gate::allows('ws.viewAllContacts')) {
            return;
        }
        // agent: solo contactos con una conversación asignada a la persona (tabla de la Fase 4).
        $query->whereRaw('1 = 0');
    }
}
