<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Assistant;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Queries\CampaignReport;
use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Queries\FlowAnalytics;
use App\Domain\Inbox\Queries\InboxQuery;
use App\Domain\Intelligence\Ai\Features\Summaries\ProfileSummary;
use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Segments\Models\Segment;
use App\Domain\Segments\Queries\SegmentTrends;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Lo que el asistente «Pregúntale a la IA» sabe de la pantalla donde está la persona (inteligencia.md §6.3): hechos ya
 * calculados por las consultas de cada módulo, nunca la base cruda. Solo si la persona puede ver eso con su rol (las
 * mismas reglas que la pantalla); si no, o si no existe, el asistente responde sin contexto. Se arma con el espacio de
 * trabajo y el rol de la petición (`ResolveWorkspace`): dentro de `CurrentWorkspace::run` no hay rol y los permisos
 * dirían que no. Sin datos de contacto
 * directos: del perfil va lo agregado (compras, ticket, RFM, riesgo), sin nombre, correo ni teléfono.
 */
final class AssistantContext
{
    public const KINDS = ['home', 'contact', 'segment', 'campaign', 'flow', 'conversation', 'leads', 'results', 'other'];

    private const MAX_FACTS = 60;

    public function __construct(
        private readonly CampaignReport $campaigns,
        private readonly FlowAnalytics $flows,
        private readonly InboxQuery $inbox,
    ) {}

    /**
     * @return array{kind: string, facts: array<string, mixed>}|null
     */
    public function build(Workspace $workspace, string $kind, ?string $id, string $userId): ?array
    {
        try {
            $facts = match ($kind) {
                'contact' => $id !== null ? $this->contact($workspace, $id) : null,
                'conversation' => $id !== null ? $this->conversation($workspace, $id, $userId) : null,
                'segment' => $id !== null ? $this->segment($workspace, $id) : null,
                'campaign' => $id !== null ? $this->campaign($workspace, $id) : null,
                'flow' => $id !== null ? $this->flow($id) : null,
                'leads' => $this->leads($workspace, $userId),
                default => null,
            };
        } catch (Throwable) {
            // Sin permiso, borrado o una consulta que falla: el asistente responde igual, sin la pantalla.
            return null;
        }
        if ($facts === null || $facts === []) {
            return null;
        }
        $clean = PiiScrubber::scrub(self::flatten($facts));

        return ['kind' => $kind, 'facts' => is_array($clean) ? $clean : []];
    }

    /** @return array<string, mixed>|null */
    private function contact(Workspace $workspace, string $id): ?array
    {
        if (! Gate::allows('ws.viewAssignedContacts')) {
            return null;
        }
        $contact = ContactAccess::find($id);

        return self::profile($workspace, $contact);
    }

    /** @return array<string, mixed>|null */
    private function conversation(Workspace $workspace, string $id, string $userId): ?array
    {
        if (! Gate::allows('ws.useInbox')) {
            return null;
        }
        $key = PrefixedId::decode(Conversation::ID_PREFIX, $id);
        $conversation = $key !== null ? $this->inbox->visible(Conversation::query(), $userId, Gate::allows('ws.viewAllContacts'))->find($key) : null;
        if (! $conversation instanceof Conversation) {
            return null;
        }
        $contact = Contact::query()->whereKey($conversation->contact_id)->first();

        return array_filter([
            'conversacion' => array_filter([
                'estado' => $conversation->getAttribute('status'),
                'modo_ia' => $conversation->getAttribute('ai_mode'),
                'intencion' => $conversation->getAttribute('intent'),
                'sin_leer' => (int) $conversation->getAttribute('unread_count'),
                'etiquetas' => $conversation->getAttribute('labels'),
            ], fn ($v) => $v !== null && $v !== []),
            'cliente' => $contact instanceof Contact ? self::profile($workspace, $contact) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private static function profile(Workspace $workspace, Contact $contact): array
    {
        $facts = ProfileSummary::facts($workspace, $contact);
        unset($facts['nombre']);

        return $facts;
    }

    /** @return array<string, mixed>|null */
    private function segment(Workspace $workspace, string $id): ?array
    {
        if (! Gate::allows('ws.viewAllContacts')) {
            return null;
        }
        $segment = Segment::findByPublicId($id);
        if (! $segment instanceof Segment) {
            return null;
        }
        $trend = SegmentTrends::forWorkspace($workspace->id, [$segment->id])[$segment->id] ?? null;

        return array_filter([
            'grupo' => $segment->name,
            'personas' => $segment->member_count,
            'cambio_de_personas_30_dias' => $trend['change'] ?? null,
            'compras_del_grupo' => round((float) $segment->revenue_ws),
            'moneda' => (string) $workspace->currency,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed>|null */
    private function campaign(Workspace $workspace, string $id): ?array
    {
        if (! Gate::allows('ws.viewDashboards')) {
            return null;
        }
        $campaign = Campaign::findByPublicId($id);
        if (! $campaign instanceof Campaign) {
            return null;
        }

        return ['campana' => (string) $campaign->getAttribute('name'), 'estado' => $campaign->getAttribute('status'), 'resultados' => $this->campaigns->handle($workspace, $campaign)];
    }

    /** @return array<string, mixed>|null */
    private function flow(string $id): ?array
    {
        if (! Gate::allows('ws.viewDashboards')) {
            return null;
        }
        $flow = Flow::findByPublicId($id);
        if (! $flow instanceof Flow) {
            return null;
        }

        return ['automatizacion' => (string) $flow->getAttribute('name'), 'estado' => $flow->getAttribute('status'), 'resultados_30_dias' => $this->flows->handle($flow, now()->subDays(30)->toImmutable())];
    }

    /** @return array<string, mixed>|null */
    private function leads(Workspace $workspace, string $userId): ?array
    {
        if (! Gate::allows('ws.useLeads')) {
            return null;
        }
        $mine = ! Gate::allows('ws.viewAllContacts');
        $base = fn () => DB::table('leads')->where('workspace_id', $workspace->id)->when($mine, fn ($q) => $q->where('owner_id', $userId));
        $open = $base()->whereNotIn('stage', ['won', 'lost'])->selectRaw('stage, COUNT(*) AS n')->groupBy('stage')->pluck('n', 'stage')->map(fn ($n) => (int) $n)->all();
        $hot = (int) $base()->whereNotIn('stage', ['won', 'lost'])->where('temperature', 'hot')->count();
        $month = now()->startOfMonth();
        $won = $base()->where('stage', 'won')->where('won_at', '>=', $month)->selectRaw('COUNT(*) AS n, COALESCE(SUM(value_ws), 0) AS v')->first();

        return [
            'leads_abiertos_por_etapa' => $open,
            'leads_calientes' => $hot,
            'ganados_este_mes' => (int) ($won->n ?? 0),
            'valor_ganado_este_mes' => round((float) ($won->v ?? 0)),
            'moneda' => (string) $workspace->currency,
        ];
    }

    /**
     * Los hechos en un nivel con claves de ruta, solo lo escalar y corto (máximo 60): lo que el modelo necesita para
     * hablar de la pantalla, sin listas largas.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public static function flatten(array $facts, string $prefix = ''): array
    {
        $out = [];
        foreach ($facts as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $out += self::flatten($value, $path);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || (is_string($value) && mb_strlen($value) <= 80)) {
                $out[$path] = $value;
            }
            if (count($out) >= self::MAX_FACTS) {
                break;
            }
        }

        return array_slice($out, 0, self::MAX_FACTS, true);
    }
}
