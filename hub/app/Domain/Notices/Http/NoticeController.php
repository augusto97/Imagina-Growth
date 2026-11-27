<?php

declare(strict_types=1);

namespace App\Domain\Notices\Http;

use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Support\BookingNoticeSetup;
use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Notices\Models\Notice;
use App\Domain\Notices\Models\NoticeLog;
use App\Domain\Notices\Models\NoticePreference;
use App\Domain\Notices\Support\NoticeLogPresenter;
use App\Domain\Notices\Support\NoticePreferences;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Support\Ids\PrefixedId;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Centro de avisos, «Mis avisos» y la sección «Avisos» de citas y leads (lote 0.4.0, docs/avisos.md). Dentro de
 * /api/w/{workspace}. Cada persona ve solo sus avisos.
 */
final class NoticeController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET notices?limit= — los últimos avisos de quien pide, con cuántos no ha leído. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $limit = max(1, min(100, (int) $request->query('limit', 30)));
        $mine = Notice::query()->where('user_id', $this->userId($request));
        $rows = (clone $mine)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (Notice $n) => [
                'id' => $n->publicId(), 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body, 'url' => $n->url,
                'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
            'unread' => (clone $mine)->whereNull('read_at')->count(),
        ]);
    }

    /** GET notices/unread — solo el número (la campana lo consulta seguido). */
    public function unread(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');

        return response()->json(['unread' => Notice::query()->where('user_id', $this->userId($request))->whereNull('read_at')->count()]);
    }

    /** POST notices/read {ids?: string[]} — marca leídos esos avisos, o todos sin `ids`. */
    public function read(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $data = $request->validate(['ids' => ['sometimes', 'array', 'max:200'], 'ids.*' => ['string', 'max:40']]);
        $query = Notice::query()->where('user_id', $this->userId($request))->whereNull('read_at');
        if (isset($data['ids'])) {
            $query->whereIn('id', array_values(array_filter(array_map(fn ($id) => PrefixedId::decode(Notice::ID_PREFIX, (string) $id), $data['ids']))));
        }
        $query->update(['read_at' => now(), 'updated_at' => now()]);

        return $this->unread($request);
    }

    /** GET notices/preferences — «Mis avisos». */
    public function preferences(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $userId = $this->userId($request);
        $saved = NoticePreference::query()->where('user_id', $userId)->first();

        return response()->json($this->presentPreferences($userId, $saved));
    }

    /** PUT notices/preferences {settings: {grupo: {canal: bool, scope?}}, phone?} */
    public function savePreferences(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $data = $request->validate([
            'settings' => ['sometimes', 'array'],
            'settings.*' => ['array'],
            'settings.*.scope' => ['sometimes', 'in:all,mine'],
            'settings.*.in_app' => ['sometimes', 'boolean'],
            'settings.*.email' => ['sometimes', 'boolean'],
            'settings.*.push' => ['sometimes', 'boolean'],
            'settings.*.whatsapp' => ['sometimes', 'boolean'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()\-]{7,30}$/'],
        ], ['phone.regex' => __('Escribe el WhatsApp con el código de país, por ejemplo +57 300 123 4567.')]);
        $userId = $this->userId($request);
        $row = NoticePreferences::save($this->current->get(), $userId, $this->current->role(), (array) ($data['settings'] ?? []),
            isset($data['phone']) ? (string) $data['phone'] : null, array_key_exists('phone', $data));

        return response()->json($this->presentPreferences($userId, $row));
    }

    /** GET bookings/appointments/{appointment}/notices — qué se le avisó al cliente y al equipo de esta cita. */
    public function appointment(string $appointment): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $model = Appointment::findByPublicId($appointment) ?? abort(404);
        // El rol agente solo ve las citas de sus contactos.
        ContactAccess::find(PrefixedId::encode(Contact::ID_PREFIX, $model->contact_id));
        $rows = NoticeLog::query()->where('subject_type', 'appointment')->where('subject_id', $model->id)
            ->orderBy('audience')->orderBy('created_at')->orderBy('id')->limit(200)->get();

        return response()->json(['data' => NoticeLogPresenter::list($rows)]);
    }

    /** GET leads/{lead}/notices — los avisos del lead y de sus citas. */
    public function lead(Request $request, string $lead): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $model = Lead::findByPublicId($lead) ?? abort(404);
        if (! Gate::allows('ws.viewAllContacts') && $model->owner_id !== $this->userId($request)) {
            abort(404);
        }
        $appointments = Appointment::query()->where('lead_id', $model->id)->pluck('id');
        $rows = NoticeLog::query()
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('subject_type', 'lead')->where('subject_id', $model->id))
                ->orWhere(fn ($w) => $w->where('subject_type', 'appointment')->whereIn('subject_id', $appointments)))
            ->orderBy('audience')->orderBy('created_at')->orderBy('id')->limit(200)->get();

        return response()->json(['data' => NoticeLogPresenter::list($rows)]);
    }

    /** GET bookings/notices/setup — «Así avisamos» de la agenda. */
    public function bookingSetup(): JsonResponse
    {
        Gate::authorize('ws.useLeads');

        return response()->json(BookingNoticeSetup::for($this->current->get()));
    }

    /** @return array<string, mixed> */
    private function presentPreferences(string $userId, ?NoticePreference $saved): array
    {
        $role = $this->current->role();
        $phone = NoticePreferences::phone($userId, $saved);

        return [
            'settings' => NoticePreferences::for($userId, $role, $saved),
            'channels' => NoticePreferences::CHANNELS,
            'can_choose_scope' => NoticePreferences::manager($role),
            'phone' => $saved?->phone,
            'phone_hint' => $phone !== null && $saved?->phone === null ? Pii::maskPhone($phone) : null,
            'manager' => $role === WorkspaceRole::Owner || $role === WorkspaceRole::Admin,
        ];
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
