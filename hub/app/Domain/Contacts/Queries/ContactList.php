<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Queries;

use App\Domain\Bookings\Models\Appointment;
use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Segments\Models\Segment;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\Pii;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * La lista de personas (modulos.md M03, «Clientes → Personas»): filtros, orden y filas. La usan la lista, la exportación
 * y las acciones masivas («Agregar a un grupo»), así lo que se exporta o se agrupa es exactamente lo que se ve.
 *
 * Filtros: búsqueda con el índice ciego, etapa, ciudad, grupo RFM, canal con consentimiento de marketing, compró o no,
 * etiqueta, grupo de clientes (`segment`, cualquier segmento) y, en negocios de servicios, la temperatura de su lead
 * abierto. Cada fila trae los canales con consentimiento y, si se pide (`services`), su lead abierto y su próxima cita.
 */
final class ContactList
{
    /** Orden → columna. */
    public const SORTS = [
        'last_seen' => 'last_seen_at',
        'last_order' => 'last_order_at',
        'revenue' => 'revenue_total_ws',
        'orders' => 'orders_count',
        'created' => 'created_at',
        'lead_score' => 'lead_score',
    ];

    /** Canales de marketing que se muestran en la lista (ContactConsent::CHANNELS sin push ni ads). */
    public const CHANNELS = ['whatsapp', 'email', 'sms'];

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'stage' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:120'],
            'rfm_segment' => ['nullable', 'string', 'max:32'],
            'consent' => ['nullable', Rule::in(self::CHANNELS)],
            'buyers' => ['nullable', 'in:1,0'],
            'tag' => ['nullable', 'string', 'max:40'],
            'segment' => ['nullable', 'string', 'max:40'],
            'temperature' => ['nullable', Rule::in(['hot', 'warm', 'cold'])],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
        ];
    }

    /**
     * Contactos vigentes (sin fusionar) que la persona puede ver, con los filtros y el orden aplicados.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Contact>
     */
    public static function query(Workspace $workspace, array $filters): Builder
    {
        $query = Contact::query()->whereNull('merged_into_id');
        ContactAccess::scope($query);
        ContactSearch::apply($query, $workspace, is_string($filters['q'] ?? null) ? $filters['q'] : '');

        $text = fn (string $key): ?string => is_string($filters[$key] ?? null) && trim($filters[$key]) !== '' ? trim($filters[$key]) : null;
        $query->when($text('stage'), fn ($q, $v) => $q->where('lifecycle_stage', $v))
            ->when($text('city'), fn ($q, $v) => $q->where('city', $v))
            ->when($text('rfm_segment'), fn ($q, $v) => $q->where('rfm_segment', $v))
            ->when($text('tag'), fn ($q, $v) => $q->whereJsonContains('tags', $v))
            ->when(in_array($filters['buyers'] ?? null, ['1', '0'], true), fn ($q) => $filters['buyers'] === '1' ? $q->where('orders_count', '>', 0) : $q->where('orders_count', 0))
            ->when($text('consent'), fn ($q, $v) => $q->whereExists(fn ($s) => $s->from('contact_consents')
                ->whereColumn('contact_consents.contact_id', 'contacts.id')->where('contact_consents.workspace_id', $workspace->id)
                ->where('contact_consents.channel', $v)->where('contact_consents.purpose', 'marketing')->where('contact_consents.status', 'granted')))
            ->when($text('temperature'), fn ($q, $v) => $q->whereExists(fn ($s) => $s->from('leads')
                ->whereColumn('leads.contact_id', 'contacts.id')->where('leads.workspace_id', $workspace->id)
                ->whereNull('leads.won_at')->whereNull('leads.lost_at')->where('leads.temperature', $v)));
        if (($segmentId = $text('segment')) !== null) {
            // Un grupo de otro espacio de trabajo no existe aquí (BelongsToWorkspace): la lista queda vacía.
            $segment = Segment::findByPublicId($segmentId);
            $query->whereIn('id', fn ($q) => $q->from('segment_memberships')->select('contact_id')
                ->where('workspace_id', $workspace->id)->where('segment_id', $segment->id ?? '-'));
        }

        $sort = self::SORTS[is_string($filters['sort'] ?? null) ? $filters['sort'] : 'last_seen'] ?? 'last_seen_at';
        // En orden descendente MariaDB deja al final a los que no tienen el dato (nunca compraron, sin puntaje).
        $query->orderByDesc($sort)->orderByDesc('id');

        return $query;
    }

    /**
     * Las filas de la lista: datos enmascarados según el rol, canales con consentimiento de marketing y, con
     * `$services`, el lead abierto (etapa, temperatura, puntaje y valor) y la próxima cita activa. Consultas en lote,
     * nunca una por fila.
     *
     * @param  Collection<int, Contact>  $contacts
     * @return list<array<string, mixed>>
     */
    public static function rows(Workspace $workspace, Collection $contacts, bool $services = false): array
    {
        $ids = array_values(array_map(fn (Contact $c) => (string) $c->id, $contacts->all()));
        $unmasked = ContactAccess::canSeePii();
        $consents = [];
        if ($ids !== []) {
            foreach (DB::table('contact_consents')->where('workspace_id', $workspace->id)->whereIn('contact_id', $ids)
                ->where('purpose', 'marketing')->where('status', 'granted')->whereIn('channel', self::CHANNELS)->get(['contact_id', 'channel']) as $r) {
                $consents[(string) $r->contact_id][] = (string) $r->channel;
            }
        }
        [$leads, $appointments] = $services && $ids !== [] ? [self::openLeads($workspace, $ids), self::nextAppointments($ids)] : [[], []];

        return array_values($contacts->map(function (Contact $c) use ($unmasked, $consents, $services, $leads, $appointments): array {
            $channels = $consents[$c->id] ?? [];
            $row = [
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
                'created_at' => $c->getAttribute('created_at')?->toIso8601String(),
                'rfm_segment' => $c->getAttribute('rfm_segment'),
                'hdyhau' => $c->getAttribute('hdyhau'),
                'tags' => $c->getAttribute('tags') ?? [],
                'consents' => array_values(array_intersect(self::CHANNELS, $channels)),
            ];
            if ($services) {
                $row['lead'] = $leads[$c->id] ?? null;
                $row['next_appointment_at'] = $appointments[$c->id] ?? null;
            }

            return $row;
        })->all());
    }

    /**
     * El lead abierto más reciente de cada contacto, con el nombre de su etapa.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private static function openLeads(Workspace $workspace, array $ids): array
    {
        $leads = Lead::query()->whereIn('contact_id', $ids)->whereNull('won_at')->whereNull('lost_at')
            ->orderByDesc('created_at')->get(['id', 'contact_id', 'pipeline_id', 'stage', 'temperature', 'score', 'value_ws', 'service', 'first_response_at', 'created_at']);
        if ($leads->isEmpty()) {
            return [];
        }
        $pipelines = LeadPipeline::query()->whereIn('id', $leads->pluck('pipeline_id')->filter()->unique()->values()->all())->get()->keyBy('id');
        $default = Pipelines::default($workspace->id);
        $out = [];
        foreach ($leads as $lead) {
            if (isset($out[$lead->contact_id])) {
                continue;
            }
            $pipeline = $lead->pipeline_id !== null ? ($pipelines->get($lead->pipeline_id) ?? $default) : $default;
            $stage = $pipeline->stage($lead->stage);
            $out[$lead->contact_id] = [
                'id' => $lead->publicId(),
                'stage' => $lead->stage,
                'stage_label' => is_array($stage) ? (string) $stage['label'] : $lead->stage,
                'temperature' => $lead->temperature,
                'score' => (int) $lead->score,
                'value' => $lead->value_ws,
                'service' => $lead->service,
                'answered' => $lead->first_response_at !== null,
                'created_at' => $lead->created_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * La próxima cita activa (reservada, confirmada o reprogramada) de cada contacto.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private static function nextAppointments(array $ids): array
    {
        return Appointment::query()->whereIn('contact_id', $ids)->whereIn('status', Appointment::ACTIVE)->where('starts_at', '>=', now())
            ->groupBy('contact_id')->selectRaw('contact_id, MIN(starts_at) AS next_at')->get()
            ->mapWithKeys(fn ($r) => [(string) $r->getAttribute('contact_id') => Carbon::parse((string) $r->getAttribute('next_at'))->toIso8601String()])
            ->all();
    }
}
