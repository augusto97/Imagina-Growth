<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot;

use App\Domain\Flows\Actions\SaveFlow;
use App\Domain\Flows\Graph\Graph;
use App\Domain\Flows\Graph\GraphValidator;
use App\Domain\Flows\Graph\NodeTypes;
use App\Domain\Flows\Library\LibraryAdapter;
use App\Domain\Flows\Library\LibraryGraph;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Support\FlowRules;
use App\Domain\Flows\Support\InvalidFlow;
use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Copilot\Models\AiDraft;
use App\Domain\Intelligence\Ai\Copilot\Support\CopilotCatalog;
use App\Domain\Intelligence\Ai\Copilot\Support\CopilotFailed;
use App\Domain\Intelligence\Ai\Copilot\Support\CopilotPrompts;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Support\NarrativeWriter;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Copiloto de flujos (inteligencia.md §6.4): de «Un flujo para recuperar clientes
 * que no compran hace 60 días con WhatsApp y correo» a un grafo válido con
 * `GraphValidator` y un disparador válido con `FlowRules`. Aplicarlo crea un
 * **borrador** con `SaveFlow`: nunca publica ni activa.
 *
 * Las plantillas van por clave: las de correo de la biblioteca se instalan al
 * aplicar (como en la biblioteca de flujos) y las de WhatsApp que falten quedan
 * como pendientes en el borrador, para elegir la aprobada.
 */
final class FlowCopilot
{
    public function __construct(
        private readonly AiGateway $ai,
        private readonly NarrativeWriter $writer,
        private readonly CopilotCatalog $catalog,
        private readonly FlowRules $rules,
        private readonly GraphValidator $validator,
    ) {}

    /**
     * @throws AiUnavailable
     * @throws CopilotFailed
     */
    public function propose(Workspace $workspace, ?string $userId, string $prompt): AiDraft
    {
        $request = new AiRequest($workspace->id, 'copilot', CopilotPrompts::FLOW."\n\n".CopilotPrompts::SEGMENT_GUIDE,
            [['role' => 'user', 'content' => $this->context($workspace, $prompt)]], self::schema());
        $result = $this->call($request);
        [$proposal, $problems, $warnings] = $this->check($workspace, (array) $result->json);
        $attempts = [['problems' => $problems]];
        if ($problems !== []) {
            $result = $this->call($request->followUp((string) json_encode($result->json, JSON_UNESCAPED_UNICODE),
                "El flujo no es válido:\n- ".implode("\n- ", $problems)."\nCorrígelo y devuelve la propuesta completa otra vez."));
            [$proposal, $problems, $warnings] = $this->check($workspace, (array) $result->json);
            $attempts[] = ['problems' => $problems];
            if ($problems !== []) {
                throw new CopilotFailed(__('El copiloto no logró un flujo válido. Intenta con un pedido más concreto o arma el flujo en el constructor.'), $problems);
            }
        }
        $proposal['warnings'] = $warnings;
        $proposal['steps'] = self::steps($proposal['graph']);
        $facts = ['pedido' => $prompt, 'flujo' => ['nombre' => $proposal['name'], 'disparador' => $proposal['trigger'], 'pasos' => $proposal['graph']['nodes']]];
        $summary = $this->writer->write(
            AiRequest::ask($workspace->id, 'copilot', CopilotPrompts::SUMMARY, 'Resume este flujo (a quién le llega, qué pasos y cuándo). Hechos: '.json_encode($facts, JSON_UNESCAPED_UNICODE)),
            $facts, fn () => implode(' ', array_map(fn (array $s) => $s['label'].'.', $proposal['steps'])));
        $proposal['summary'] = $summary->text;
        $proposal['summary_source'] = $summary->source;

        return AiDraft::query()->create([
            'workspace_id' => $workspace->id, 'user_id' => $userId, 'kind' => 'flow', 'prompt' => mb_substr($prompt, 0, 2000),
            'proposal' => $proposal, 'model' => $result->model, 'validation' => ['attempts' => $attempts, 'summary' => $summary->validation],
        ]);
    }

    /**
     * Crea el flujo en borrador (nunca lo publica ni lo activa) e instala las
     * plantillas de correo de la biblioteca que pide.
     *
     * @return array{flow: Flow, issues: array<string, list<string>>}
     */
    public function apply(Workspace $workspace, AiDraft $draft, User $user): array
    {
        if ($draft->kind !== 'flow' || $draft->status !== AiDraft::DRAFT) {
            throw new CopilotFailed(__('Esta propuesta ya se aplicó o se descartó.'));
        }
        $proposal = $draft->proposal;
        foreach ((array) ($proposal['graph']['nodes'] ?? []) as $node) {
            $key = $node['config']['template_key'] ?? null;
            $channel = NodeTypes::channelOf((string) ($node['type'] ?? '')) ?? (($node['type'] ?? '') === NodeTypes::NOTIFY_TEAM ? 'email' : null);
            if (is_string($key) && $channel !== null && LibraryAdapter::installable($key) && TemplateRef::resolve($workspace->id, $channel, null, $key) === null) {
                TemplateLibrary::install($workspace, $key, null, $user->id);
            }
        }
        try {
            $result = app(SaveFlow::class)->handle(null, [
                'name' => (string) $proposal['name'], 'description' => $proposal['description'] ?? null, 'trigger' => $proposal['trigger'],
                'goal' => $proposal['goal'], 'reentry_policy' => $proposal['reentry_policy'], 'graph' => $proposal['graph'],
            ], $user->id);
        } catch (InvalidFlow $e) {
            throw new CopilotFailed(__('La propuesta ya no es válida: :msg', ['msg' => $e->getMessage()]), array_map(fn ($v) => is_array($v) ? implode(' ', $v) : (string) $v, $e->errors));
        }
        $proposal['applied'] = [['id' => $result['flow']->publicId(), 'name' => $result['flow']->name, 'issues' => $result['issues']]];
        $draft->forceFill(['status' => AiDraft::APPLIED, 'applied_type' => 'flow', 'applied_id' => $result['flow']->id, 'proposal' => $proposal])->save();

        return $result;
    }

    private function call(AiRequest $request): AiResult
    {
        $result = $this->ai->generate($request);
        if ($result->refused()) {
            throw new AiUnavailable('refusal', __('El modelo no quiso hacer esta propuesta.'));
        }
        if (! is_array($result->json) || $result->json === []) {
            throw new CopilotFailed(__('El copiloto no devolvió un flujo. Intenta de nuevo con un pedido más concreto.'));
        }

        return $result;
    }

    private function context(Workspace $workspace, string $prompt): string
    {
        $email = array_values(array_unique([
            ...array_values(array_filter(array_map(fn (array $t) => $t['library_key'] ?? null, $this->catalog->templates($workspace, 'email')))),
            ...array_map(fn (array $l) => $l['key'], $this->catalog->emailLayouts()),
        ]));
        $whatsapp = array_values(array_filter(array_map(fn (array $t) => ($t['approved'] ?? false) === true ? ['template_id' => $t['id'], 'nombre' => $t['name'], 'texto' => $t['text'] ?? null] : null,
            $this->catalog->templates($workspace, 'whatsapp'))));

        return (string) json_encode([
            'pedido' => $prompt,
            'hoy' => CarbonImmutable::now($workspace->timezone ?: 'America/Bogota')->format('Y-m-d'),
            'canales_conectados' => $this->catalog->activeChannels($workspace),
            'plantillas_correo_por_clave' => $email,
            'plantillas_whatsapp_aprobadas' => $whatsapp,
            'segmentos' => array_map(fn (array $s) => array_intersect_key($s, array_flip(['id', 'name', 'members', 'system_key'])), $this->catalog->segments(40)),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['name', 'description', 'trigger_json', 'goal_event', 'reentry_mode', 'reentry_days', 'graph_json'], 'properties' => [
                'name' => ['type' => 'string'], 'description' => ['type' => ['string', 'null']],
                'trigger_json' => ['type' => 'string'], 'goal_event' => ['type' => ['string', 'null']],
                'reentry_mode' => ['type' => 'string', 'enum' => ['once', 'every_days', 'always']], 'reentry_days' => ['type' => ['integer', 'null']],
                'graph_json' => ['type' => 'string'],
            ]];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: list<string>}
     */
    private function check(Workspace $workspace, array $raw): array
    {
        $problems = [];
        $warnings = [];
        $trigger = json_decode((string) ($raw['trigger_json'] ?? ''), true);
        $graphData = json_decode((string) ($raw['graph_json'] ?? ''), true);
        $settings = [];
        try {
            $settings = $this->rules->normalize([
                'trigger' => is_array($trigger) ? $trigger : [],
                'goal' => is_string($raw['goal_event'] ?? null) && $raw['goal_event'] !== '' ? ['event' => $raw['goal_event'], 'filters' => []] : null,
                'reentry_policy' => ['mode' => $raw['reentry_mode'] ?? 'once', 'days' => (int) ($raw['reentry_days'] ?? 0)],
            ]);
        } catch (InvalidFlow $e) {
            foreach ($e->errors as $field => $msg) {
                $problems[(string) $field] = is_array($msg) ? implode(' ', $msg) : (string) $msg;
            }
        }
        if (! is_array($graphData)) {
            $problems['graph'] = __('graph_json no es un JSON válido.');
            $graphData = [];
        }
        $graph = Graph::fromArray($graphData);
        $clean = $graph->toArray();
        $active = $this->catalog->activeChannels($workspace);
        $pendingWhatsapp = [];
        foreach ($this->validator->validate($graph, $workspace->id) as $nodeId => $messages) {
            $node = $graph->nodes[$nodeId] ?? null;
            $type = (string) ($node['type'] ?? '');
            $key = $node['config']['template_key'] ?? null;
            $channel = NodeTypes::channelOf($type);
            foreach ($messages as $message) {
                // Correo de la biblioteca: se instala al aplicar. WhatsApp sin plantilla aprobada: queda pendiente en el borrador.
                if ($channel !== null && str_starts_with($message, (string) __('Elige una plantilla de'))) {
                    if ($channel === 'email' && is_string($key) && LibraryAdapter::installable($key)) {
                        continue;
                    }
                    if ($channel === 'whatsapp') {
                        $pendingWhatsapp[] = (string) $nodeId;

                        continue;
                    }
                }
                $problems['graph.'.($nodeId === '' ? 'flujo' : $nodeId)] = isset($problems['graph.'.$nodeId]) ? $problems['graph.'.$nodeId].' '.$message : $message;
            }
        }
        foreach ($graph->nodes as $node) {
            $channel = NodeTypes::channelOf((string) $node['type']);
            if ($channel !== null && $active !== [] && ! in_array($channel, $active, true)) {
                $warnings[] = __('El flujo envía por :channel, que no está conectado: conéctalo antes de activarlo.', ['channel' => CampaignCopilot::channelLabel($channel)]);
            }
        }
        if ($pendingWhatsapp !== []) {
            $warnings[] = __('Falta elegir la plantilla aprobada de WhatsApp en :n paso(s) antes de activar el flujo.', ['n' => count($pendingWhatsapp)]);
        }
        $edges = array_map(fn (array $e) => array_filter(['from' => (string) $e['from'], 'to' => (string) $e['to'], 'branch' => isset($e['branch']) ? (string) $e['branch'] : null], fn ($v) => $v !== null), $clean['edges']);
        /** @var list<array{from: string, to: string, branch?: string}> $edges */
        $clean['nodes'] = LibraryGraph::layout($clean['nodes'], $edges);

        return [[
            'name' => mb_substr(trim((string) ($raw['name'] ?? '')) ?: __('Flujo del copiloto'), 0, 150),
            'description' => is_string($raw['description'] ?? null) ? mb_substr($raw['description'], 0, 480) : null,
            'trigger' => $settings['trigger'] ?? (is_array($trigger) ? $trigger : ['type' => null]),
            'goal' => $settings['goal'] ?? null,
            'reentry_policy' => $settings['reentry_policy'] ?? ['mode' => 'once'],
            'graph' => $clean,
            'pending_whatsapp' => $pendingWhatsapp,
        ], $problems, array_values(array_unique($warnings))];
    }

    /**
     * Pasos legibles en orden (para la revisión y el resumen sin IA).
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return list<array{id: string, type: string, label: string}>
     */
    public static function steps(array $graph): array
    {
        $out = [];
        foreach ($graph['nodes'] as $node) {
            $c = (array) ($node['config'] ?? []);
            $label = match ((string) $node['type']) {
                NodeTypes::TRIGGER => __('Entra al flujo'),
                NodeTypes::WAIT => __('Espera :n :unit', ['n' => $c['duration']['value'] ?? '?', 'unit' => match ((string) ($c['duration']['unit'] ?? 'days')) {
                    'minutes' => __('minutos'), 'hours' => __('horas'), default => __('días'),
                }]),
                NodeTypes::WAIT_EVENT => __('Espera el evento :event', ['event' => (string) ($c['event'] ?? '')]),
                NodeTypes::CONDITION => __('Condición'),
                NodeTypes::CHANNEL_SPLIT => __('Elige canal: :list', ['list' => implode(', ', (array) ($c['channels'] ?? []))]),
                NodeTypes::AB_SPLIT => __('Prueba A/B'),
                NodeTypes::SEND_WHATSAPP => __('Envía WhatsApp (:t)', ['t' => (string) ($c['template_key'] ?? $c['template_id'] ?? '?')]),
                NodeTypes::SEND_EMAIL => __('Envía correo (:t)', ['t' => (string) ($c['template_key'] ?? $c['template_id'] ?? '?')]),
                NodeTypes::SEND_SMS => __('Envía SMS'),
                NodeTypes::COUPON => __('Crea un cupón de :n:unit', ['n' => (string) ($c['amount'] ?? ''), 'unit' => ($c['kind'] ?? '') === 'percent' ? '%' : '']),
                NodeTypes::TAG => __('Etiqueta: :tags', ['tags' => implode(', ', (array) ($c['add'] ?? []))]),
                NodeTypes::GIVE_POINTS => __('Da :n puntos', ['n' => (string) ($c['points'] ?? '')]),
                NodeTypes::NOTIFY_TEAM => __('Avisa al equipo'),
                NodeTypes::EXIT => __('Sale del flujo'),
                default => (string) $node['type'],
            };
            $out[] = ['id' => (string) $node['id'], 'type' => (string) $node['type'], 'label' => $label];
        }

        return $out;
    }
}
