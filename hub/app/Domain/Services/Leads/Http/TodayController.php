<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Http;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Inbox\Http\InboxController;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\Message;
use App\Domain\Services\Leads\Assistant\LeadMessageWriter;
use App\Domain\Services\Leads\Assistant\LeadReach;
use App\Domain\Services\Leads\Assistant\SendLeadMessage;
use App\Domain\Services\Leads\Assistant\TodayList;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadAssistantMark;
use App\Domain\Services\Leads\Models\LeadTask;
use App\Domain\Workspaces\Models\User;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * «Hoy», el asistente del vendedor (M18): la lista del día, el mensaje sugerido por
 * la IA, enviar con un clic (por el despachador), marcar hecho y posponer. Quien no
 * ve todos los contactos (rol agente) solo ve y toca lo suyo; la dueña ve lo de todos
 * y puede filtrar por vendedor.
 */
final class TodayController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET leads/today?owner=all|me|none|usr_… */
    public function index(Request $request, TodayList $list): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $data = $request->validate(['owner' => ['nullable', 'string', 'max:40']]);
        $me = $this->userId($request);
        $seesAll = Gate::allows('ws.viewAllContacts');
        $owner = $seesAll ? ($data['owner'] ?? 'all') : 'me';
        $ownerId = match (true) {
            $owner === 'all' => null,
            $owner === 'me' => $me,
            $owner === 'none' => 'none',
            default => PrefixedId::decode(User::ID_PREFIX, $owner) ?? abort(422),
        };

        return response()->json($list->handle($this->current->get(), $ownerId) + ['owner' => $owner, 'can_see_all' => $seesAll]);
    }

    /** POST leads/today/suggest {key}: el mensaje que la IA propone para este ítem y por dónde saldría. */
    public function suggest(Request $request, LeadMessageWriter $writer): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        [$kind, $lead, $task] = $this->item((string) $request->validate(['key' => ['required', 'string', 'max:120']])['key'], $request);
        if ($lead === null) {
            return response()->json(['text' => null, 'source' => 'fallback', 'reach' => ['mode' => 'none', 'channel' => null, 'reason' => 'no_lead']]);
        }
        $ws = $this->current->get();
        $reach = LeadReach::for($ws->id, Contact::query()->find($lead->contact_id));
        $lastIn = Message::query()->where('contact_id', $lead->contact_id)->where('direction', 'in')->latest('created_at')->value('id');
        $cacheKey = RedisKey::for($ws->id, 'leads:today:suggest:'.sha1(implode('|', [$kind, $lead->id, $task->id ?? '', $lead->updated_at?->getTimestamp(), $lastIn, $reach['channel']])));
        $suggestion = Cache::get($cacheKey);
        if (! is_array($suggestion)) {
            $suggestion = $writer->write($ws, $lead, match ($kind) {
                'reply' => 'reply', 'hot' => 'first_contact', 'next' => 'next_step', 'stale' => 'stale', default => 'task',
            }, ['task' => $task?->title, 'channel' => $reach['channel']]);
            // Solo se guarda lo que escribió la IA: si no estaba disponible, la próxima vez lo intenta otra vez.
            if ($suggestion['source'] === 'ai') {
                Cache::put($cacheKey, $suggestion, now()->addHours(12));
            }
        }

        return response()->json($suggestion + ['reach' => ['mode' => $reach['mode'], 'channel' => $reach['channel'], 'reason' => $reach['reason']]]);
    }

    /** POST leads/today/send {key, text, client_id}: envía por el despachador y marca el ítem como hecho. */
    public function send(Request $request, SendLeadMessage $send): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $data = $request->validate(['key' => ['required', 'string', 'max:120'], 'text' => ['required', 'string', 'max:1000'], 'client_id' => ['required', 'uuid']]);
        [, $lead, $task] = $this->item($data['key'], $request);
        if ($lead === null) {
            abort(422, __('Este ítem no tiene un lead al que escribirle.'));
        }
        try {
            $result = $send->handle($this->current->get(), $lead, $data['text'], $this->userId($request), $data['client_id']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if (in_array($result['status'], [DispatchResult::QUEUED, DispatchResult::DUPLICATE], true)) {
            $this->markDone($data['key'], $lead, $task, $request);

            return response()->json($result, $result['status'] === DispatchResult::QUEUED ? 201 : 200);
        }
        if ($result['status'] === DispatchResult::DEFERRED) {
            return response()->json($result + ['message' => __('Por la hora legal del contacto, el mensaje no se puede enviar ahora. Intenta después de :at.', ['at' => (string) $result['until']])], 409);
        }

        return response()->json($result + ['message' => self::denied((string) $result['reason'], $result['channel'])], 422);
    }

    /** POST leads/today/done {key} */
    public function done(Request $request): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $key = (string) $request->validate(['key' => ['required', 'string', 'max:120']])['key'];
        [, $lead, $task] = $this->item($key, $request);
        $this->markDone($key, $lead, $task, $request);

        return response()->json(['ok' => true]);
    }

    /** POST leads/today/snooze {key, days}: vuelve a aparecer ese día (a las 8:00 de la zona del negocio). */
    public function snooze(Request $request): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $data = $request->validate(['key' => ['required', 'string', 'max:120'], 'days' => ['required', 'integer', Rule::in([1, 2, 3, 7])]]);
        [, $lead, $task] = $this->item($data['key'], $request);
        $until = CarbonImmutable::now($this->current->get()->timezone ?: 'America/Bogota')->addDays((int) $data['days'])->setTime(8, 0)->utc();
        if ($task !== null) {
            $task->forceFill(['due_at' => $until])->save();
        } else {
            LeadAssistantMark::query()->updateOrCreate(['item_key' => $data['key']], [
                'workspace_id' => $this->current->id(), 'lead_id' => $lead?->id, 'status' => 'snoozed', 'until' => $until, 'user_id' => $this->userId($request),
            ]);
        }

        return response()->json(['ok' => true, 'until' => $until->toIso8601String()]);
    }

    private function markDone(string $key, ?Lead $lead, ?LeadTask $task, Request $request): void
    {
        if ($task !== null) {
            $task->forceFill(['status' => 'done', 'done_at' => now(), 'done_by' => $this->userId($request)])->save();

            return;
        }
        LeadAssistantMark::query()->updateOrCreate(['item_key' => $key], [
            'workspace_id' => $this->current->id(), 'lead_id' => $lead?->id, 'status' => 'done', 'until' => null, 'user_id' => $this->userId($request),
        ]);
    }

    /**
     * El ítem por su clave (`reply:ld_…:momento`, `hot:ld_…`, `next:…`, `stale:…` o `task:ltk_…`),
     * con el permiso de quien pide: el rol agente solo toca sus leads y sus tareas.
     *
     * @return array{0: string, 1: Lead|null, 2: LeadTask|null}
     */
    private function item(string $key, Request $request): array
    {
        $parts = explode(':', $key);
        $kind = $parts[0];
        $me = $this->userId($request);
        $seesAll = Gate::allows('ws.viewAllContacts');
        if ($kind === 'task') {
            $task = LeadTask::findByPublicId($parts[1] ?? null) ?? abort(404);
            if (! $seesAll && $task->owner_id !== $me) {
                abort(404);
            }
            $lead = $task->lead_id !== null ? Lead::query()->find($task->lead_id) : null;

            return [$kind, $lead, $task];
        }
        if (! in_array($kind, ['reply', 'hot', 'next', 'stale'], true)) {
            abort(422, __('Ítem desconocido.'));
        }
        $lead = Lead::findByPublicId($parts[1] ?? null) ?? abort(404);
        if (! $seesAll && $lead->owner_id !== $me) {
            abort(404);
        }

        return [$kind, $lead, null];
    }

    private static function denied(string $reason, ?string $channel): string
    {
        return match ($reason) {
            'no_template' => __('Pasaron más de 24 horas desde el último mensaje del cliente: elige en Ajustes de leads la plantilla de WhatsApp para seguimientos.'),
            'no_channel', 'no_contact' => __('Este lead no tiene WhatsApp ni correo para escribirle.'),
            'window_closed' => __('Ventana cerrada: pasaron más de 24 horas desde el último mensaje del cliente y este canal no permite plantillas.'),
            'no_consent' => $channel === 'email'
                ? __('El contacto no aceptó recibir mensajes comerciales por correo.')
                : __('El contacto no aceptó mensajes de marketing por WhatsApp: espera a que vuelva a escribir para responderle.'),
            default => InboxController::denied($reason, Channel::tryFrom((string) $channel)),
        };
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
