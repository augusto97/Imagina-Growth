<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Http;

use App\Domain\Inbox\Models\QuickReply;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Respuestas rápidas de la bandeja: todos las usan; las administra `manageInbox`. */
final class QuickReplyController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(): JsonResponse
    {
        Gate::authorize('ws.useInbox');

        return response()->json(['data' => QuickReply::query()->orderBy('shortcut')->get()->map(fn (QuickReply $r) => self::present($r))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageInbox');
        $reply = QuickReply::query()->create($this->validated($request) + ['workspace_id' => $this->current->id(), 'created_by' => (string) $request->user()?->getAuthIdentifier()]);

        return response()->json(self::present($reply), 201);
    }

    public function update(Request $request, string $reply): JsonResponse
    {
        Gate::authorize('ws.manageInbox');
        $model = $this->find($reply);
        $model->fill($this->validated($request, $model->id))->save();

        return response()->json(self::present($model));
    }

    public function destroy(string $reply): JsonResponse
    {
        Gate::authorize('ws.manageInbox');
        $this->find($reply)->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array{shortcut: string, title: string, body: string} */
    private function validated(Request $request, ?string $ignore = null): array
    {
        $data = $request->validate([
            'shortcut' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('quick_replies', 'shortcut')->where('workspace_id', $this->current->id())->ignore($ignore)],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
        ], ['shortcut.regex' => __('El atajo solo lleva minúsculas, números, guiones o guion bajo.'), 'shortcut.unique' => __('Ya hay una respuesta con ese atajo.')]);

        return ['shortcut' => (string) $data['shortcut'], 'title' => (string) $data['title'], 'body' => (string) $data['body']];
    }

    private function find(string $publicId): QuickReply
    {
        $id = PrefixedId::decode(QuickReply::ID_PREFIX, $publicId);
        $reply = $id !== null ? QuickReply::query()->find($id) : null;
        abort_if($reply === null, 404);

        return $reply;
    }

    /** @return array<string, string> */
    private static function present(QuickReply $r): array
    {
        return ['id' => $r->publicId(), 'shortcut' => $r->shortcut, 'title' => $r->title, 'body' => $r->body];
    }
}
