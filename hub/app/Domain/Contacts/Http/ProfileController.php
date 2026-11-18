<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Http;

use App\Analytics\Ch;
use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Consent\Models\ConsentEvent;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Actions\MergeContacts;
use App\Domain\Contacts\Actions\RevertContactMerge;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactMerge;
use App\Domain\Contacts\Models\ContactNote;
use App\Domain\Contacts\Queries\ProfileQuery;
use App\Domain\Identity\IdentityResolver;
use App\Domain\Privacy\Actions\ExportContactData;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Perfil 360 (modulos.md M03). */
final class ProfileController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly ProfileQuery $profile) {}

    /** GET contacts/{contact} */
    public function show(string $contact): JsonResponse
    {
        return response()->json($this->profile->header($this->current->get(), ContactAccess::find($contact)));
    }

    /** GET contacts/{contact}/timeline?groups[]=&before= */
    public function timeline(Request $request, string $contact): JsonResponse
    {
        $data = $request->validate([
            'groups' => ['nullable', 'array'],
            'groups.*' => [Rule::in(array_keys(ProfileQuery::TIMELINE_GROUPS))],
            'before' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        return response()->json($this->profile->timeline($this->current->get(), ContactAccess::find($contact), array_values((array) ($data['groups'] ?? [])), $data['before'] ?? null, (int) ($data['limit'] ?? 50)));
    }

    /** GET contacts/{contact}/orders */
    public function orders(Request $request, string $contact): JsonResponse
    {
        Gate::authorize('ws.viewAllContacts');

        return response()->json($this->profile->orders(ContactAccess::find($contact), max(1, (int) $request->query('page', 1))));
    }

    /** GET contacts/{contact}/products */
    public function products(string $contact): JsonResponse
    {
        return response()->json($this->profile->products($this->current->get(), ContactAccess::find($contact)));
    }

    /** GET contacts/{contact}/identities */
    public function identities(string $contact): JsonResponse
    {
        $model = ContactAccess::find($contact);
        $merges = ContactMerge::query()->where('winner_id', $model->id)->orderByDesc('created_at')->get()->map(fn (ContactMerge $m) => [
            'id' => $m->publicId(), 'loser_id' => 'ct_'.$m->loser_id, 'reason' => $m->reason,
            'created_at' => $m->created_at?->toIso8601String(), 'reverted_at' => $m->reverted_at?->toIso8601String(), 'can_revert' => $m->canRevert(),
        ]);

        return response()->json(['identities' => $this->profile->identities($model), 'merges' => $merges]);
    }

    /** GET contacts/{contact}/privacy */
    public function privacy(string $contact): JsonResponse
    {
        $model = ContactAccess::find($contact);
        $ids = ExportContactData::contactIds($model);

        return response()->json([
            'consents' => ContactConsent::query()->whereIn('contact_id', $ids)->get()->map(fn (ContactConsent $c) => [
                'channel' => $c->channel, 'purpose' => $c->purpose, 'status' => $c->status, 'source' => $c->source,
                'version' => $c->legal_text_version, 'granted_at' => $c->granted_at?->toIso8601String(), 'revoked_at' => $c->revoked_at?->toIso8601String(),
            ]),
            'evidence' => ConsentEvent::query()->whereIn('contact_id', $ids)->orderByDesc('occurred_at')->limit(100)->get()->map(fn (ConsentEvent $e) => [
                'channel' => $e->channel, 'action' => $e->action, 'source' => $e->getAttribute('source'), 'version' => $e->getAttribute('legal_text_version'),
                'occurred_at' => $e->getAttribute('occurred_at')?->toIso8601String(),
                'evidence' => array_intersect_key($e->evidence ?? [], array_flip(['page_url', 'event', 'order_id', 'form_snapshot', 'note'])),
            ]),
            'requests' => DataRequest::query()->whereIn('contact_id', $ids)->orderByDesc('created_at')->get()->map(fn (DataRequest $r) => [
                'id' => $r->publicId(), 'kind' => $r->kind, 'status' => $r->status, 'due_at' => $r->due_at?->toIso8601String(),
                'downloadable' => $r->kind === 'access' && $r->status === 'completed' && isset($r->evidence['export']),
            ]),
        ]);
    }

    /** PATCH contacts/{contact} — rectificación de datos y etiquetas. */
    public function update(Request $request, string $contact, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.editContacts');
        $model = ContactAccess::find($contact);
        $data = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'birthday' => ['sometimes', 'nullable', 'date', 'before:today'],
            'tags' => ['sometimes', 'array', 'max:50'],
            'tags.*' => ['string', 'max:40'],
            'custom_attributes' => ['sometimes', 'array', 'max:100'],
        ]);
        if (isset($data['tags'])) {
            $data['tags'] = array_values(array_unique(array_map(fn ($t) => trim((string) $t), $data['tags'])));
        }
        $model->fill($data);
        if ($model->isDirty('first_name') || $model->isDirty('last_name')) {
            $model->setAttribute('name_prefix_bidx', IdentityResolver::namePrefixes($model->workspace_id, ($model->first_name ?? '').' '.($model->last_name ?? '')));
        }
        $changed = array_keys($model->getDirty());
        $model->save();
        $audit->handle('contact.updated', $model, ['fields' => $changed], $model->workspace_id);

        return $this->show($contact);
    }

    /** GET/POST contacts/{contact}/notes */
    public function notes(string $contact): JsonResponse
    {
        $model = ContactAccess::find($contact);
        $notes = ContactNote::query()->whereIn('contact_id', ExportContactData::contactIds($model))->orderByDesc('created_at')->get();
        $users = User::query()->whereIn('id', $notes->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return response()->json(['data' => $notes->map(fn (ContactNote $n) => [
            'id' => $n->publicId(), 'body' => $n->body, 'author' => $n->user_id ? ($users[$n->user_id] ?? null) : null,
            'mine' => $n->user_id === auth()->id(), 'created_at' => $n->getAttribute('created_at')?->toIso8601String(),
        ])]);
    }

    public function addNote(Request $request, string $contact): JsonResponse
    {
        Gate::authorize('ws.editContacts');
        $model = ContactAccess::find($contact);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        ContactNote::query()->create(['workspace_id' => $model->workspace_id, 'contact_id' => $model->id, 'user_id' => auth()->id(), 'body' => trim($data['body'])]);

        return $this->notes($contact);
    }

    public function deleteNote(string $note): JsonResponse
    {
        $model = ContactNote::findByPublicId($note) ?? abort(404);
        if ($model->user_id !== auth()->id() && ! Gate::allows('ws.manageWorkspace')) {
            abort(403);
        }
        $model->delete();

        return response()->json(['ok' => true]);
    }

    /** POST contacts/{contact}/merge {other_id} — fusión manual (el contacto de la URL gana). */
    public function merge(Request $request, string $contact, MergeContacts $merge): JsonResponse
    {
        Gate::authorize('ws.mergeContacts');
        $winner = ContactAccess::find($contact);
        $data = $request->validate(['other_id' => ['required', 'string']]);
        $loser = ContactAccess::find((string) $data['other_id']);
        try {
            $result = $merge->handle($winner, $loser, 'manual', (string) auth()->id());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['other_id' => $e->getMessage()]);
        }
        $this->remapAnonymous($result['anonymous_ids'], $winner->id);

        return response()->json(['merge_id' => $result['merge']->publicId()] + $this->profile->header($this->current->get(), $winner->refresh()));
    }

    /** POST merges/{merge}/revert */
    public function revert(string $merge, RevertContactMerge $revert): JsonResponse
    {
        Gate::authorize('ws.mergeContacts');
        $model = ContactMerge::findByPublicId($merge) ?? abort(404);
        $anonymous = $revert->handle($model, (string) auth()->id());
        $this->remapAnonymous($anonymous, $model->loser_id);

        return response()->json(['ok' => true, 'contact_id' => 'ct_'.$model->loser_id]);
    }

    /** POST contacts/{contact}/consents {channel, action, note} — desde el panel, con constancia. */
    public function consent(Request $request, string $contact, RecordConsent $record, RecordAuditLog $audit): JsonResponse
    {
        $model = ContactAccess::find($contact);
        $data = $request->validate([
            'channel' => ['required', Rule::in(ContactConsent::CHANNELS)],
            'action' => ['required', 'in:granted,revoked'],
            'note' => ['required_if:action,granted', 'nullable', 'string', 'max:500'],
        ]);
        // Revocar lo puede pedir la persona a cualquiera del equipo; otorgar a mano exige un rol administrador.
        Gate::authorize($data['action'] === 'granted' ? 'ws.manageWorkspace' : 'ws.editContacts');
        $record->handle($this->current->get(), $model->id, $data['channel'], 'marketing', $data['action'], 'admin', null, now(), array_filter([
            'note' => $data['note'] ?? null, 'user_id' => auth()->id(),
        ]), authoritative: true);
        $audit->handle('consent.'.$data['action'].'_by_admin', $model, ['channel' => $data['channel']], $model->workspace_id);

        return $this->privacy($contact);
    }

    /** @param list<string> $anonymous */
    private function remapAnonymous(array $anonymous, string $contactId): void
    {
        if ($anonymous === []) {
            return;
        }
        $workspace = $this->current->get();
        app(Ch::class)->insert('identity_map', array_map(fn (string $a) => [
            'workspace_id' => $workspace->ch_id, 'anonymous_id' => $a, 'contact_id' => $contactId, 'linked_at' => now()->utc()->format('Y-m-d H:i:s.v'),
        ], $anonymous), async: true);
        foreach ($anonymous as $a) {
            Cache::put(RedisKey::for($workspace->id, 'anon:'.$a), $contactId, now()->addDay());
        }
    }
}
