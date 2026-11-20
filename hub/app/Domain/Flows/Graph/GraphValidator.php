<?php

declare(strict_types=1);

namespace App\Domain\Flows\Graph;

use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Segments\Definition\DefinitionValidator;
use App\Domain\Segments\Definition\InvalidDefinition;
use App\Domain\Services\Leads\Support\LeadDefaults;
use App\Domain\Workspaces\Models\User;
use App\Ingest\Schema\SchemaRegistry;
use App\Support\Ids\PrefixedId;
use App\Support\Net\SafeUrl;
use Illuminate\Support\Facades\DB;

/**
 * Valida el grafo antes de publicar (hub/CLAUDE.md §7): un solo disparador,
 * todo conectado, sin ciclos sin espera, ramas válidas para cada tipo, nodos
 * de envío con plantilla y configuración correcta en cada nodo. Devuelve los
 * errores por ID de nodo ('' = el flujo completo); vacío = publicable.
 */
final class GraphValidator
{
    public const MAX_NODES = 100;

    private const ID = '/^[A-Za-z0-9_-]{1,32}$/';

    /** @var array<string, list<string>> */
    private array $errors = [];

    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly DefinitionValidator $definitions,
    ) {}

    /** @return array<string, list<string>> */
    public function validate(Graph $graph, string $workspaceId): array
    {
        $this->errors = [];
        if (count($graph->nodes) > self::MAX_NODES) {
            $this->add('', __('Un flujo puede tener como máximo :max nodos.', ['max' => self::MAX_NODES]));
        }
        $triggers = $graph->nodesOfType(NodeTypes::TRIGGER);
        if (count($triggers) !== 1) {
            $this->add('', __('El flujo necesita exactamente un disparador.'));
        }
        if (count($graph->nodes) <= 1) {
            $this->add('', __('Agrega al menos un paso después del disparador.'));
        }

        foreach ($graph->nodes as $id => $node) {
            if (preg_match(self::ID, $id) !== 1) {
                $this->add($id, __('El identificador del nodo no es válido.'));
            }
            if (! NodeTypes::exists($node['type'])) {
                $this->add($id, __('Tipo de nodo desconocido.'));

                continue;
            }
            $this->config($id, $node['type'], $node['config'], $workspaceId);
        }

        $this->edges($graph);
        $this->reachability($graph);
        $this->cycles($graph);

        return $this->errors;
    }

    private function add(string $node, string $message): void
    {
        if (! in_array($message, $this->errors[$node] ?? [], true)) {
            $this->errors[$node][] = $message;
        }
    }

    private function edges(Graph $graph): void
    {
        $seen = [];
        foreach ($graph->edges as $edge) {
            $from = $graph->node($edge['from']);
            $to = $graph->node($edge['to']);
            if ($from === null || $to === null) {
                $this->add($edge['from'], __('Una conexión apunta a un paso que no existe.'));

                continue;
            }
            if ($to['type'] === NodeTypes::TRIGGER) {
                $this->add($edge['to'], __('Nada puede volver al disparador.'));
            }
            if ($edge['from'] === $edge['to']) {
                $this->add($edge['from'], __('Un paso no puede conectarse consigo mismo.'));
            }
            if (! NodeTypes::exists($from['type'])) {
                continue;
            }
            $valid = NodeTypes::branches($from['type'], $from['config']);
            if ($valid === []) {
                $this->add($edge['from'], __('La salida termina el flujo: no puede tener pasos después.'));
            } elseif (! in_array($edge['branch'], $valid, true)) {
                $this->add($edge['from'], __('Rama no válida para este paso: :b.', ['b' => $edge['branch'] ?? __('siguiente')]));
            }
            $key = $edge['from'].'|'.($edge['branch'] ?? '');
            if (isset($seen[$key])) {
                $this->add($edge['from'], __('Cada salida de un paso puede ir a un solo paso.'));
            }
            $seen[$key] = true;
        }
        $trigger = $graph->triggerId();
        if ($trigger !== null && $graph->next($trigger) === null) {
            $this->add($trigger, __('Conecta el disparador con el primer paso.'));
        }
    }

    private function reachability(Graph $graph): void
    {
        $start = $graph->triggerId();
        if ($start === null) {
            return;
        }
        $seen = [$start => true];
        $queue = [$start];
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($graph->outgoing($id) as $edge) {
                if (! isset($seen[$edge['to']]) && $graph->node($edge['to']) !== null) {
                    $seen[$edge['to']] = true;
                    $queue[] = $edge['to'];
                }
            }
        }
        foreach (array_keys($graph->nodes) as $id) {
            if (! isset($seen[$id])) {
                $this->add($id, __('Este paso no está conectado al flujo.'));
            }
        }
    }

    /** Un ciclo solo se permite si pasa por una espera (si no, giraría sin parar). */
    private function cycles(Graph $graph): void
    {
        $state = [];
        $stack = [];
        $visit = function (string $id) use (&$visit, &$state, &$stack, $graph): void {
            $state[$id] = 1;
            $stack[] = $id;
            foreach ($graph->outgoing($id) as $edge) {
                $to = $edge['to'];
                if ($graph->node($to) === null) {
                    continue;
                }
                if (($state[$to] ?? 0) === 1) {
                    $cycle = array_slice($stack, (int) array_search($to, $stack, true));
                    $hasWait = false;
                    foreach ($cycle as $n) {
                        if (in_array($graph->node($n)['type'] ?? '', NodeTypes::WAITS, true)) {
                            $hasWait = true;
                        }
                    }
                    if (! $hasWait) {
                        $this->add($to, __('Este ciclo no tiene ninguna espera: agrega una antes de volver.'));
                    }
                } elseif (($state[$to] ?? 0) === 0) {
                    $visit($to);
                }
            }
            array_pop($stack);
            $state[$id] = 2;
        };
        foreach (array_keys($graph->nodes) as $id) {
            if (($state[$id] ?? 0) === 0) {
                $visit($id);
            }
        }
    }

    /** @param array<string, mixed> $c */
    private function config(string $id, string $type, array $c, string $workspaceId): void
    {
        match ($type) {
            NodeTypes::WAIT => $this->waitConfig($id, $c),
            NodeTypes::WAIT_EVENT => $this->waitEventConfig($id, $c),
            NodeTypes::CONDITION => $this->conditionConfig($id, $c),
            NodeTypes::AB_SPLIT => $this->abConfig($id, $c),
            NodeTypes::CHANNEL_SPLIT => $this->channelSplitConfig($id, $c),
            NodeTypes::SEND_WHATSAPP, NodeTypes::SEND_EMAIL, NodeTypes::SEND_SMS => $this->sendConfig($id, (string) NodeTypes::channelOf($type), $c, $workspaceId),
            NodeTypes::COUPON => $this->couponConfig($id, $c),
            NodeTypes::TAG => $this->tagConfig($id, $c),
            NodeTypes::UPDATE_ATTRIBUTE => $this->attributeConfig($id, $c),
            NodeTypes::NOTIFY_TEAM => $this->notifyConfig($id, $c, $workspaceId),
            NodeTypes::WEBHOOK => $this->webhookConfig($id, $c),
            NodeTypes::GIVE_POINTS => $this->givePointsConfig($id, $c),
            NodeTypes::GIVE_WALLET => $this->giveWalletConfig($id, $c),
            NodeTypes::CRM_TASK => $this->crmTaskConfig($id, $c, $workspaceId),
            NodeTypes::ASSIGN_AGENT => $this->assignConfig($id, $c, $workspaceId),
            NodeTypes::LEAD_STAGE => $this->leadStageConfig($id, $c, $workspaceId),
            NodeTypes::LEAD_FOLLOWUP => $this->followUpConfig($id, $c, $workspaceId),
            NodeTypes::AI_REACTIVATE => $this->reactivateConfig($id, $c, $workspaceId),
            default => null,
        };
    }

    /** @param array<string, mixed> $c */
    private function crmTaskConfig(string $id, array $c, string $workspaceId): void
    {
        $title = trim((string) ($c['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160) {
            $this->add($id, __('Escribe la tarea (máximo 160 caracteres).'));
        }
        if (! in_array($c['kind'] ?? 'task', ['task', 'call'], true)) {
            $this->add($id, __('La tarea es una tarea o una llamada.'));
        }
        $due = $c['due_in'] ?? null;
        if ($due !== null && ((Durations::seconds($due) ?? PHP_INT_MAX) > 90 * 86400)) {
            $this->add($id, __('La tarea debe vencer entre 1 minuto y 90 días después.'));
        }
        $this->assigneeConfig($id, (string) ($c['assign'] ?? 'lead_owner'), ['lead_owner', 'user'], $c['user_id'] ?? null, $workspaceId);
    }

    /** @param array<string, mixed> $c */
    private function assignConfig(string $id, array $c, string $workspaceId): void
    {
        $this->assigneeConfig($id, (string) ($c['mode'] ?? 'auto'), ['auto', 'user'], $c['user_id'] ?? null, $workspaceId);
    }

    /** @param list<string> $modes */
    private function assigneeConfig(string $id, string $mode, array $modes, mixed $userId, string $workspaceId): void
    {
        if (! in_array($mode, $modes, true)) {
            $this->add($id, __('Elige a quién se asigna.'));

            return;
        }
        if ($mode !== 'user') {
            return;
        }
        $user = PrefixedId::decode(User::ID_PREFIX, is_string($userId) ? $userId : null);
        if ($user === null || ! DB::table('workspace_user')->where('workspace_id', $workspaceId)->where('user_id', $user)->exists()) {
            $this->add($id, __('Elige una persona del equipo.'));
        }
    }

    /** @param array<string, mixed> $c */
    private function leadStageConfig(string $id, array $c, string $workspaceId): void
    {
        $stage = $c['stage'] ?? null;
        $exists = false;
        if (is_string($stage) && $stage !== '') {
            foreach (DB::table('lead_pipelines')->where('workspace_id', $workspaceId)->pluck('stages') as $raw) {
                foreach ((array) json_decode((string) $raw, true) as $s) {
                    $exists = $exists || (is_array($s) && ($s['key'] ?? null) === $stage);
                }
            }
            // Sin pipelines todavía, vale una etapa del pipeline simple (se crea con el primer lead).
            $exists = $exists || in_array($stage, array_column(LeadDefaults::STAGES, 'key'), true);
        }
        if (! $exists) {
            $this->add($id, __('Elige una etapa de tus pipelines de leads.'));
        }
        if (mb_strlen((string) ($c['lost_reason'] ?? '')) > 120) {
            $this->add($id, __('El motivo de pérdida tiene máximo 120 caracteres.'));
        }
    }

    /** @param array<string, mixed> $c */
    private function followUpConfig(string $id, array $c, string $workspaceId): void
    {
        $channels = (array) ($c['channels'] ?? ['whatsapp', 'email']);
        if ($channels === [] || array_diff($channels, ['whatsapp', 'email']) !== [] || count(array_unique($channels)) !== count($channels)) {
            $this->add($id, __('Elige por qué canales sale el seguimiento: WhatsApp, correo o los dos.'));
        }
        $template = $c['template_id'] ?? null;
        if (is_string($template) && $template !== '' && TemplateRef::resolve($workspaceId, 'whatsapp', $template, null) === null) {
            $this->add($id, __('Esa plantilla de WhatsApp no existe.'));
        }
        $step = $c['step'] ?? 1;
        if (! is_int($step) || $step < 1 || $step > 10) {
            $this->add($id, __('Indica qué número de seguimiento es (1 a 10).'));
        }
    }

    /** @param array<string, mixed> $c */
    private function reactivateConfig(string $id, array $c, string $workspaceId): void
    {
        $channels = (array) ($c['channels'] ?? ['whatsapp', 'email']);
        if ($channels === [] || array_diff($channels, ['whatsapp', 'email']) !== [] || count(array_unique($channels)) !== count($channels)) {
            $this->add($id, __('Elige por qué canales sale el mensaje: WhatsApp, correo o los dos.'));
        }
        $template = $c['template_id'] ?? null;
        if (is_string($template) && $template !== '') {
            $found = TemplateRef::resolve($workspaceId, 'whatsapp', $template, null);
            // Fuera de la ventana de 24 h el mensaje es una plantilla de marketing: no se disfraza de utilidad.
            if ($found === null || DB::table('templates')->where('workspace_id', $workspaceId)->where('id', $found)->value('category') !== 'marketing') {
                $this->add($id, __('Elige una plantilla de WhatsApp de marketing aprobada.'));
            }
        }
        if (mb_strlen(trim((string) ($c['offer'] ?? ''))) > 200) {
            $this->add($id, __('La oferta tiene máximo 200 caracteres.'));
        }
        $days = $c['lookback_days'] ?? 365;
        if (! is_int($days) || $days < 7 || $days > 730) {
            $this->add($id, __('La última conversación se busca entre 7 y 730 días atrás.'));
        }
    }

    /** @param array<string, mixed> $c */
    private function givePointsConfig(string $id, array $c): void
    {
        $points = $c['points'] ?? null;
        if (! is_numeric($points) || (int) $points != $points || (int) $points < 1 || (int) $points > 100000) {
            $this->add($id, __('Indica cuántos puntos dar (entre 1 y 100.000).'));
        }
    }

    /** @param array<string, mixed> $c */
    private function giveWalletConfig(string $id, array $c): void
    {
        $amount = $c['amount'] ?? null;
        if (! is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 100000000) {
            $this->add($id, __('Indica el monto de saldo a favor que se da.'));
        }
    }

    /** @param array<string, mixed> $c */
    private function waitConfig(string $id, array $c): void
    {
        if (($c['mode'] ?? 'duration') === 'until_time') {
            $hour = $c['hour'] ?? null;
            $days = (array) ($c['weekdays'] ?? [1, 2, 3, 4, 5, 6, 7]);
            if (! is_int($hour) || $hour < 0 || $hour > 23 || $days === [] || array_diff($days, [1, 2, 3, 4, 5, 6, 7]) !== []) {
                $this->add($id, __('Elige una hora (0 a 23) y al menos un día de la semana.'));
            }

            return;
        }
        if (Durations::seconds($c['duration'] ?? null) === null) {
            $this->add($id, __('La espera debe durar entre 1 minuto y 365 días.'));
        }
    }

    /** @param array<string, mixed> $c */
    private function waitEventConfig(string $id, array $c): void
    {
        $event = $c['event'] ?? null;
        if (! is_string($event) || ! $this->schemas->isKnownEvent($event)) {
            $this->add($id, __('Elige el evento que se espera.'));
        }
        $timeout = Durations::seconds($c['timeout'] ?? null);
        if ($timeout === null || $timeout > 90 * 86400) {
            $this->add($id, __('El tiempo máximo de espera debe estar entre 1 minuto y 90 días.'));
        }
        if (! Filters::valid((array) ($c['filters'] ?? []))) {
            $this->add($id, __('Los filtros del evento no son válidos.'));
        }
    }

    /** @param array<string, mixed> $c */
    private function conditionConfig(string $id, array $c): void
    {
        if (($c['mode'] ?? 'segment') === 'context') {
            if (! Filters::valid([['prop' => $c['path'] ?? '', 'operator' => $c['operator'] ?? '', 'value' => $c['value'] ?? null]])) {
                $this->add($id, __('La condición sobre los datos del disparador no es válida.'));
            }

            return;
        }
        try {
            $this->definitions->validate((array) ($c['definition'] ?? []));
        } catch (InvalidDefinition $e) {
            $this->add($id, $e->getMessage());
        }
    }

    /** @param array<string, mixed> $c */
    private function abConfig(string $id, array $c): void
    {
        $variants = (array) ($c['variants'] ?? []);
        $keys = array_map(fn ($v) => is_array($v) ? ($v['key'] ?? null) : null, $variants);
        $sum = array_sum(array_map(fn ($v) => is_array($v) ? (int) ($v['weight'] ?? 0) : 0, $variants));
        if (count($variants) < 2 || count($variants) > 4 || array_diff($keys, NodeTypes::AB_KEYS) !== [] || count(array_unique($keys)) !== count($keys) || $sum !== 100) {
            $this->add($id, __('La división A/B necesita de 2 a 4 variantes cuyos pesos sumen 100.'));
        }
    }

    /** @param array<string, mixed> $c */
    private function channelSplitConfig(string $id, array $c): void
    {
        $channels = (array) ($c['channels'] ?? []);
        if ($channels === [] || array_diff($channels, NodeTypes::CHANNELS) !== [] || count(array_unique($channels)) !== count($channels)) {
            $this->add($id, __('Elige los canales en el orden en que se intentan.'));
        }
    }

    /** @param array<string, mixed> $c */
    private function sendConfig(string $id, string $channel, array $c, string $workspaceId): void
    {
        $template = TemplateRef::resolve($workspaceId, $channel, $c['template_id'] ?? null, $c['template_key'] ?? null);
        if ($template === null) {
            $this->add($id, __('Elige una plantilla de :channel para este envío.', ['channel' => match ($channel) {
                'whatsapp' => 'WhatsApp', 'sms' => 'SMS', default => __('correo'),
            }]));
        }
        $kind = $c['kind'] ?? 'marketing';
        if (! in_array($kind, ['marketing', 'utility', 'transactional'], true)) {
            $this->add($id, __('Tipo de mensaje no válido.'));
        } elseif ($kind !== 'marketing' && mb_strlen(trim((string) ($c['reclassify_reason'] ?? ''))) < 10) {
            // cumplimiento.md §4.1: lo ambiguo es marketing; reclasificarlo exige una justificación.
            $this->add($id, __('Explica por qué este mensaje no es de marketing (mínimo 10 caracteres).'));
        }
    }

    /** @param array<string, mixed> $c */
    private function couponConfig(string $id, array $c): void
    {
        $kind = $c['kind'] ?? null;
        $amount = (float) ($c['amount'] ?? 0);
        $days = (int) ($c['expires_in_days'] ?? 0);
        if (! in_array($kind, ['percent', 'fixed_cart', 'free_shipping'], true)) {
            $this->add($id, __('Elige el tipo de cupón.'));
        } elseif ($kind !== 'free_shipping' && ($amount <= 0 || ($kind === 'percent' && $amount > 100))) {
            $this->add($id, __('El descuento debe ser mayor que 0 (y un porcentaje, como máximo 100).'));
        }
        if ($days < 1 || $days > 90) {
            $this->add($id, __('El cupón debe vencer entre 1 y 90 días después.'));
        }
    }

    /** @param array<string, mixed> $c */
    private function tagConfig(string $id, array $c): void
    {
        $tags = [...(array) ($c['add'] ?? []), ...(array) ($c['remove'] ?? [])];
        if ($tags === [] || array_filter($tags, fn ($t) => ! is_string($t) || trim($t) === '' || mb_strlen($t) > 40) !== []) {
            $this->add($id, __('Escribe al menos una etiqueta (máximo 40 caracteres cada una).'));
        }
    }

    /** @param array<string, mixed> $c */
    private function attributeConfig(string $id, array $c): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string) ($c['key'] ?? '')) !== 1 || ! (is_scalar($c['value'] ?? null) || ($c['value'] ?? null) === null)) {
            $this->add($id, __('Elige el atributo personalizado y su valor.'));
        }
    }

    /** @param array<string, mixed> $c */
    private function notifyConfig(string $id, array $c, string $workspaceId): void
    {
        $channel = $c['channel'] ?? 'email';
        if (! in_array($channel, ['email', 'whatsapp'], true)) {
            $this->add($id, __('El aviso al equipo va por correo o WhatsApp.'));

            return;
        }
        $recipients = (array) ($c['recipients'] ?? []);
        if ($recipients === [] && ($c['to_owners'] ?? true) !== true) {
            $this->add($id, __('Elige a quién avisar.'));
        }
        if ($channel === 'whatsapp' && $recipients === []) {
            $this->add($id, __('Para avisar por WhatsApp escribe los números del equipo.'));
        }
        $text = trim((string) ($c['message'] ?? ''));
        if ($text === '' || mb_strlen($text) > 500) {
            $this->add($id, __('Escribe el aviso (máximo 500 caracteres).'));
        }
        if (TemplateRef::resolve($workspaceId, (string) $channel, $c['template_id'] ?? null, $c['template_key'] ?? TemplateRef::TEAM_ALERT) === null) {
            $this->add($id, __('Falta la plantilla de avisos al equipo (:key).', ['key' => TemplateRef::TEAM_ALERT]));
        }
    }

    /** @param array<string, mixed> $c */
    private function webhookConfig(string $id, array $c): void
    {
        $url = (string) ($c['url'] ?? '');
        $secureOnly = ! app()->environment('local', 'testing');
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ($secureOnly && ! str_starts_with($url, 'https://'))) {
            $this->add($id, __('Escribe una URL completa con https://.'));
        } elseif (($problem = SafeUrl::problem($url)) !== null) {
            $this->add($id, $problem);
        }
    }
}
