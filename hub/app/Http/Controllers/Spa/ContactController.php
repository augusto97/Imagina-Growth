<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa;

use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Queries\ContactList;
use App\Domain\Contacts\Queries\ContactSearch;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lista de personas y búsqueda de ⌘K (M03). Aplica la regla de M01: un `agent` solo ve los contactos que tiene
 * asignados (por conversación de la bandeja).
 */
final class ContactController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * GET /api/w/{workspace}/contacts — la lista de personas con filtros y orden (`ContactList`). `services=1` suma a
     * cada fila su lead abierto y su próxima cita (negocios de servicios).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewAssignedContacts');
        $filters = $request->validate(ContactList::rules() + ['services' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer']]);
        $workspace = $this->current->get();
        $page = ContactList::query($workspace, $filters)->paginate(min(100, max(10, (int) ($filters['per_page'] ?? 25))));

        return response()->json([
            'data' => ContactList::rows($workspace, collect($page->items()), (bool) ($filters['services'] ?? false)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ]);
    }

    /**
     * GET /api/w/{workspace}/contacts/search?q= — la paleta ⌘K: hasta 8 contactos por nombre, correo o teléfono, sin
     * contar el total. Mismos permisos y enmascarado que la lista.
     */
    public function search(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewAssignedContacts');
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }
        $query = Contact::query()->whereNull('merged_into_id');
        $this->scopeForRole($query);
        ContactSearch::apply($query, $this->current->get(), mb_substr($q, 0, 120));
        $unmasked = Gate::allows('ws.exportContacts');

        return response()->json([
            'data' => $query->orderByDesc('last_seen_at')->orderByDesc('id')->limit(8)->get()->map(fn (Contact $c) => [
                'id' => $c->publicId(),
                'name' => $c->displayName(),
                'email' => $c->email === null ? null : ($unmasked ? $c->email : Pii::maskEmail($c->email)),
                'phone' => $c->phone === null ? null : ($unmasked ? $c->phone : Pii::maskPhone($c->phone)),
                'city' => $c->getAttribute('city'),
                'orders_count' => (int) $c->getAttribute('orders_count'),
            ])->values(),
        ]);
    }

    /** @param Builder<Contact> $query */
    private function scopeForRole(Builder $query): void
    {
        // agent: solo contactos con una conversación de la bandeja asignada a la persona.
        ContactAccess::scope($query);
    }
}
