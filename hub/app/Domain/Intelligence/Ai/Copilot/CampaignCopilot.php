<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot;

use App\Domain\Campaigns\Http\CampaignPresenter;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Consent\Policy\LegalWindow;
use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Copilot\Models\AiDraft;
use App\Domain\Intelligence\Ai\Copilot\Support\CampaignDryRun;
use App\Domain\Intelligence\Ai\Copilot\Support\CopilotCatalog;
use App\Domain\Intelligence\Ai\Copilot\Support\CopilotFailed;
use App\Domain\Intelligence\Ai\Copilot\Support\CopilotPrompts;
use App\Domain\Intelligence\Ai\Copilot\Support\Fmt;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Data\AiTool;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Support\NarrativeWriter;
use App\Domain\Intelligence\Ai\Support\NumberValidator;
use App\Domain\Segments\Actions\SaveSegment;
use App\Domain\Segments\Definition\DefinitionValidator;
use App\Domain\Segments\Definition\InvalidDefinition;
use App\Domain\Segments\Models\Segment;
use App\Domain\Segments\Queries\SegmentPreview;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Copiloto de campañas (modulos.md M10, inteligencia.md §6.4): de «Haz una campaña
 * de Black Friday para VIP con 20%» a una propuesta completa —segmento, canales,
 * textos, horario, cupón, costo y revisión de cumplimiento— que queda en
 * `ai_drafts`. **Nunca envía**: aplicarla crea campañas en borrador con la misma
 * revisión del asistente, y solo la aprobación normal las programa.
 *
 * Todo lo que propone el modelo se revisa aquí con las reglas del producto: el
 * árbol del segmento con `DefinitionValidator`, las plantillas contra las del
 * espacio de trabajo (WhatsApp solo aprobadas), el horario contra la ventana legal
 * del país, el cupón contra sus límites y las cifras de los textos contra los
 * hechos. Si algo falla se le pide una corrección **una** vez; si sigue mal, lo
 * obligatorio se rechaza con un error claro y lo opcional se quita con un aviso.
 */
final class CampaignCopilot
{
    private const MAX_STEPS = 6;

    public function __construct(
        private readonly AiGateway $ai,
        private readonly NarrativeWriter $writer,
        private readonly NumberValidator $numbers,
        private readonly CopilotCatalog $catalog,
        private readonly CampaignDryRun $dryRun,
        private readonly DefinitionValidator $definitions,
        private readonly SegmentPreview $preview,
    ) {}

    /**
     * @throws AiUnavailable si la IA no está disponible (el asistente sigue funcionando a mano)
     * @throws CopilotFailed si no hubo una propuesta válida tras la corrección
     */
    public function propose(Workspace $workspace, ?string $userId, string $prompt): AiDraft
    {
        $request = new AiRequest($workspace->id, 'copilot', CopilotPrompts::CAMPAIGN."\n\n".CopilotPrompts::SEGMENT_GUIDE,
            [['role' => 'user', 'content' => $this->context($workspace, $prompt)]], self::schema());
        $tools = $this->tools($workspace);
        $result = $this->call($request, $tools);
        [$proposal, $problems, $warnings] = $this->check($workspace, (array) $result->json, $prompt, false);
        $attempts = [['problems' => $problems]];
        if ($problems !== []) {
            $result = $this->call($request->followUp((string) json_encode($result->json, JSON_UNESCAPED_UNICODE),
                "Corrige la propuesta:\n- ".implode("\n- ", $problems)."\nDevuelve la propuesta completa otra vez."), $tools);
            [$proposal, $problems, $warnings] = $this->check($workspace, (array) $result->json, $prompt, true);
            $attempts[] = ['problems' => $problems];
            if ($problems !== []) {
                throw new CopilotFailed(__('El copiloto no logró una propuesta válida. Intenta con un pedido más concreto o crea la campaña con el asistente.'), $problems);
            }
        }
        $proposal = $this->measure($workspace, $proposal);
        $proposal['warnings'] = $warnings;
        $summary = $this->summarize($workspace, $proposal, $prompt);
        $proposal['summary'] = $summary['text'];
        $proposal['summary_source'] = $summary['source'];

        return AiDraft::query()->create([
            'workspace_id' => $workspace->id, 'user_id' => $userId, 'kind' => 'campaign', 'prompt' => mb_substr($prompt, 0, 2000),
            'proposal' => $proposal, 'model' => $result->model,
            'validation' => ['attempts' => $attempts, 'summary' => $summary['validation']],
        ]);
    }

    /**
     * Cambios de la persona antes de aplicar (nombre, horario, textos, cupón,
     * segmento existente, control). Se revisan con las mismas reglas, sin IA.
     *
     * @param  array<string, mixed>  $edits
     */
    public function edit(Workspace $workspace, AiDraft $draft, array $edits): AiDraft
    {
        $raw = self::toRaw($draft->proposal);
        foreach (['name', 'holdout_pct'] as $field) {
            if (array_key_exists($field, $edits)) {
                $raw[$field] = $edits[$field];
            }
        }
        if (is_array($edits['schedule'] ?? null)) {
            $raw['schedule'] = array_replace($raw['schedule'], array_intersect_key($edits['schedule'], array_flip(['date', 'hour', 'send_mode'])));
        }
        if (is_array($edits['coupon'] ?? null)) {
            $raw['coupon'] = array_replace($raw['coupon'], array_intersect_key($edits['coupon'], array_flip(['enabled', 'kind', 'amount', 'expires_in_days', 'minimum_amount'])));
        }
        if (is_string($edits['segment_id'] ?? null)) {
            $raw['segment'] = ['mode' => 'existing', 'segment_id' => $edits['segment_id'], 'name' => null, 'definition_json' => null];
        }
        foreach ((array) ($edits['channels'] ?? []) as $i => $channelEdits) {
            if (isset($raw['channels'][$i]) && is_array($channelEdits)) {
                $raw['channels'][$i] = array_replace($raw['channels'][$i], array_intersect_key($channelEdits, array_flip(CopilotCatalog::COPY_SLOTS)));
            }
        }
        [$proposal, $problems, $warnings] = $this->check($workspace, $raw, $draft->prompt, true, false);
        if ($problems !== []) {
            throw new CopilotFailed(__('Revisa los cambios: hay datos que no son válidos.'), $problems);
        }
        $proposal = $this->measure($workspace, $proposal);
        $proposal['warnings'] = $warnings;
        $proposal['summary'] = $this->fallbackSummary($proposal);
        $proposal['summary_source'] = 'template';
        $proposal['edited'] = true;
        $draft->forceFill(['proposal' => $proposal])->save();

        return $draft;
    }

    /**
     * Crea los borradores: el segmento nuevo (si lo hay), las plantillas de correo
     * con los textos propuestos y una campaña en borrador por canal. Nunca programa
     * ni envía: eso sigue siendo la aprobación del asistente.
     *
     * @return list<Campaign>
     */
    public function apply(Workspace $workspace, AiDraft $draft, User $user): array
    {
        if ($draft->kind !== 'campaign' || $draft->status !== AiDraft::DRAFT) {
            throw new CopilotFailed(__('Esta propuesta ya se aplicó o se descartó.'));
        }
        [$proposal, $problems] = $this->check($workspace, self::toRaw($draft->proposal), $draft->prompt, true, false);
        if ($problems !== []) {
            throw new CopilotFailed(__('La propuesta ya no es válida (¿se borró el segmento o la plantilla?). Edítala o pide otra.'), $problems);
        }

        return DB::transaction(function () use ($workspace, $draft, $proposal, $user): array {
            $segment = $proposal['segment'];
            $segmentId = $segment['segment_id'];
            if ($segment['mode'] === 'new') {
                $created = app(SaveSegment::class)->handle(null, [
                    'name' => mb_substr((string) $segment['name'], 0, 120),
                    'description' => __('Creado con el copiloto a partir de: «:prompt»', ['prompt' => mb_substr($draft->prompt, 0, 300)]),
                    'definition' => $segment['definition'],
                ], $user);
                $segmentId = $created->publicId();
            }
            $multi = count($proposal['channels']) > 1;
            $campaigns = [];
            foreach ($proposal['channels'] as $channel) {
                $templateId = $channel['template']['template_id'];
                if ($channel['template']['mode'] === 'library') {
                    // Copia propia del diseño con los textos propuestos (sin library_key: no reemplaza la de la biblioteca).
                    $industry = is_string($workspace->getAttribute('industry')) ? $workspace->getAttribute('industry') : null;
                    $template = Template::query()->create(array_replace(
                        TemplateLibrary::attributes((string) $channel['template']['library_key'], $industry, (array) $channel['template']['copy']),
                        ['workspace_id' => $workspace->id, 'created_by' => $user->id, 'name' => mb_substr($proposal['name'].' · '.__('IA'), 0, 160), 'library_key' => null],
                    ));
                    $templateId = $template->id;
                } else {
                    $templateId = CampaignPresenter::templateKey((string) $templateId);
                }
                $name = $multi ? $proposal['name'].' · '.self::channelLabel($channel['channel']) : $proposal['name'];
                $campaign = Campaign::query()->create([
                    'workspace_id' => $workspace->id, 'name' => mb_substr($name, 0, 160), 'channel' => $channel['channel'],
                    'audience' => ['segments_include' => [$segmentId], 'segments_exclude' => [], 'all_contacts' => false, 'rules_include' => [], 'rules_exclude' => []],
                    'template_id' => $templateId, 'send_mode' => $proposal['schedule']['send_mode'],
                    'send_at' => CarbonImmutable::parse((string) $proposal['schedule']['send_at']), 'holdout_pct' => (int) $proposal['holdout_pct'],
                    'created_by' => $user->id, 'status' => Campaign::DRAFT,
                ]);
                app(RecordAuditLog::class)->handle('campaign.created', $campaign, ['name' => $campaign->name, 'channel' => $campaign->channel, 'source' => 'copilot', 'draft' => $draft->publicId()], $workspace->id);
                $campaigns[] = $campaign;
            }
            $proposal['applied'] = array_map(fn (Campaign $c) => ['id' => $c->publicId(), 'channel' => $c->channel, 'name' => $c->name], $campaigns);
            $draft->forceFill(['status' => AiDraft::APPLIED, 'applied_type' => 'campaign', 'applied_id' => $campaigns[0]->id ?? null, 'proposal' => $proposal])->save();

            return $campaigns;
        });
    }

    // ── Llamada al modelo ──────────────────────────────────────────────────

    /** @param list<AiTool> $tools */
    private function call(AiRequest $request, array $tools): AiResult
    {
        $result = $this->ai->withTools($request, $tools, self::MAX_STEPS);
        if ($result->refused()) {
            throw new AiUnavailable('refusal', __('El modelo no quiso hacer esta propuesta.'));
        }
        if (! is_array($result->json) || $result->json === []) {
            throw new CopilotFailed(__('El copiloto no devolvió una propuesta. Intenta de nuevo con un pedido más concreto.'));
        }

        return $result;
    }

    private function context(Workspace $workspace, string $prompt): string
    {
        $tz = $workspace->timezone ?: 'America/Bogota';
        $tone = (array) data_get($workspace->settings ?? [], 'brand_tone', []);

        return (string) json_encode([
            'pedido' => $prompt,
            'hoy' => CarbonImmutable::now($tz)->format('Y-m-d H:i'),
            'tienda' => ['pais' => $workspace->country ?: 'CO', 'zona_horaria' => $tz, 'moneda' => $workspace->currency ?: 'COP', 'industria' => $workspace->getAttribute('industry')],
            'canales_conectados' => $this->catalog->activeChannels($workspace),
            'tono_de_marca' => $tone['summary'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** @return list<AiTool> */
    private function tools(Workspace $workspace): array
    {
        $none = ['type' => 'object', 'properties' => new \stdClass, 'required' => [], 'additionalProperties' => false];
        $review = ['type' => 'object', 'additionalProperties' => false, 'required' => ['channel', 'segment_id', 'definition_json', 'send_mode', 'date', 'hour'], 'properties' => [
            'channel' => ['type' => 'string', 'enum' => ['email', 'whatsapp', 'sms']],
            'segment_id' => ['type' => ['string', 'null']],
            'definition_json' => ['type' => ['string', 'null']],
            'send_mode' => ['type' => 'string', 'enum' => ['scheduled', 'contact_timezone', 'best_time']],
            'date' => ['type' => 'string', 'description' => 'AAAA-MM-DD'],
            'hour' => ['type' => 'integer'],
        ]];
        $runReview = function (array $in) use ($workspace): array {
            $definition = null;
            if (is_string($in['definition_json'] ?? null) && $in['definition_json'] !== '') {
                $definition = $this->definitions->validate((array) json_decode($in['definition_json'], true));
            } elseif (! is_string($in['segment_id'] ?? null) || Segment::findByPublicId($in['segment_id']) === null) {
                throw new \InvalidArgumentException('Indica un segment_id existente o un definition_json.');
            }
            [$at] = $this->schedule($workspace, (string) ($in['date'] ?? ''), (int) ($in['hour'] ?? 10), (string) ($in['send_mode'] ?? 'scheduled'));

            return CampaignDryRun::summary($this->dryRun->run($workspace, (string) $in['channel'], $definition === null ? (string) $in['segment_id'] : null, $definition, (string) ($in['send_mode'] ?? 'scheduled'), $at));
        };

        return [
            new AiTool('list_segments', 'Segmentos del espacio de trabajo con su tamaño y alcance por canal (con consentimiento).', $none,
                fn () => ['segments' => $this->catalog->segments()]),
            new AiTool('preview_segment', 'Valida un segmento nuevo (definition_json) y devuelve cuántos contactos tiene y su alcance por canal.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['definition_json'], 'properties' => ['definition_json' => ['type' => 'string']]],
                function (array $in) use ($workspace): array {
                    try {
                        $ast = $this->definitions->validate((array) json_decode((string) $in['definition_json'], true));
                    } catch (InvalidDefinition $e) {
                        return ['valid' => false, 'errors' => $e->errors];
                    }
                    $r = $this->preview->handle($workspace, $ast, false, 0);

                    return ['valid' => true, 'members' => (int) ($r['count'] ?? 0), 'reach' => $r['channels'] ?? []];
                }),
            new AiTool('list_templates', 'Plantillas existentes de un canal (WhatsApp con su aprobación de Meta) y, para correo, los diseños de la biblioteca con los textos que se pueden escribir.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['channel'], 'properties' => ['channel' => ['type' => 'string', 'enum' => ['email', 'whatsapp', 'sms']]]],
                fn (array $in) => ['templates' => $this->catalog->templates($workspace, (string) $in['channel'])]
                    + ($in['channel'] === 'email' ? ['library_layouts' => $this->catalog->emailLayouts()] : [])),
            new AiTool('estimate_cost', 'Costo estimado y destinatarios de la campaña con ese canal, segmento y horario.', $review,
                fn (array $in) => array_intersect_key($runReview($in), array_flip(['audience', 'will_send', 'cost_usd', 'cost_local', 'currency', 'estimated']))),
            new AiTool('check_compliance', 'Revisión de cumplimiento: cuántos recibirán, quiénes quedan fuera y por qué (consentimiento, ventana legal, frecuencia) y cuándo sale el primero.', $review,
                fn (array $in) => array_intersect_key($runReview($in), array_flip(['audience', 'will_send', 'excluded', 'excluded_reasons', 'deferred_reasons', 'channel_available', 'first_send_at']))),
            new AiTool('commercial_calendar', 'Próximas fechas comerciales del país (Black Friday, Día de la Madre, Amor y Amistad…) con cuándo empezar a comunicar.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['year'], 'properties' => ['year' => ['type' => ['integer', 'null']]]],
                fn (array $in) => ['events' => $this->catalog->calendar($workspace, is_int($in['year'] ?? null) ? $in['year'] : null)]),
        ];
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $text = ['type' => ['string', 'null']];

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'segment', 'channels', 'schedule', 'coupon', 'holdout_pct'], 'properties' => [
            'name' => ['type' => 'string'],
            'segment' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['mode', 'segment_id', 'name', 'definition_json'], 'properties' => [
                'mode' => ['type' => 'string', 'enum' => ['existing', 'new']],
                'segment_id' => $text, 'name' => $text, 'definition_json' => $text,
            ]],
            'channels' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                'required' => ['channel', 'template_id', 'library_key', 'subject', 'preheader', 'headline', 'intro', 'cta'], 'properties' => [
                    'channel' => ['type' => 'string', 'enum' => ['email', 'whatsapp', 'sms']],
                    'template_id' => $text, 'library_key' => $text, 'subject' => $text, 'preheader' => $text, 'headline' => $text, 'intro' => $text, 'cta' => $text,
                ]]],
            'schedule' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['date', 'hour', 'send_mode', 'reason'], 'properties' => [
                'date' => ['type' => 'string'], 'hour' => ['type' => 'integer'],
                'send_mode' => ['type' => 'string', 'enum' => ['scheduled', 'contact_timezone', 'best_time']], 'reason' => $text,
            ]],
            'coupon' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['enabled', 'kind', 'amount', 'expires_in_days', 'minimum_amount'], 'properties' => [
                'enabled' => ['type' => 'boolean'], 'kind' => ['type' => 'string', 'enum' => ['percent', 'fixed_cart', 'free_shipping']],
                'amount' => ['type' => 'number'], 'expires_in_days' => ['type' => 'integer'], 'minimum_amount' => ['type' => ['number', 'null']],
            ]],
            'holdout_pct' => ['type' => 'integer'],
        ]];
    }

    // ── Revisión con las reglas del producto ───────────────────────────────

    /**
     * @param  array<string, mixed>  $raw  la propuesta como la devuelve el modelo
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: list<string>} propuesta, problemas que piden corrección, avisos
     */
    private function check(Workspace $workspace, array $raw, string $prompt, bool $final, bool $strictCopy = true): array
    {
        $problems = [];
        $warnings = [];
        $active = $this->catalog->activeChannels($workspace);
        $tz = $workspace->timezone ?: 'America/Bogota';

        $name = trim((string) ($raw['name'] ?? ''));
        $name = $name !== '' ? mb_substr($name, 0, 150) : mb_substr(__('Campaña: :prompt', ['prompt' => $prompt]), 0, 150);

        // Segmento.
        $seg = (array) ($raw['segment'] ?? []);
        $segment = ['mode' => ($seg['mode'] ?? '') === 'new' ? 'new' : 'existing', 'segment_id' => null, 'name' => null, 'definition' => null];
        if ($segment['mode'] === 'existing') {
            $model = is_string($seg['segment_id'] ?? null) ? Segment::findByPublicId($seg['segment_id']) : null;
            if ($model === null) {
                $problems['segment'] = __('El segmento «:id» no existe: usa uno de list_segments o propone uno nuevo.', ['id' => (string) ($seg['segment_id'] ?? '')]);
            } else {
                $segment['segment_id'] = $model->publicId();
                $segment['name'] = $model->name;
            }
        } else {
            $decoded = is_string($seg['definition_json'] ?? null) ? json_decode($seg['definition_json'], true) : null;
            try {
                $segment['definition'] = $this->definitions->validate(is_array($decoded) ? $decoded : []);
                $segment['name'] = mb_substr(trim((string) ($seg['name'] ?? '')) ?: $name, 0, 120);
            } catch (InvalidDefinition $e) {
                $problems['segment'] = __('El segmento nuevo no es válido: :errors', ['errors' => implode('; ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($e->errors), $e->errors))]);
            }
        }

        // Cupón (antes de los textos: sus cifras son hechos).
        $c = (array) ($raw['coupon'] ?? []);
        $coupon = ['enabled' => (bool) ($c['enabled'] ?? false), 'kind' => in_array($c['kind'] ?? null, ['percent', 'fixed_cart', 'free_shipping'], true) ? (string) $c['kind'] : 'percent',
            'amount' => round((float) ($c['amount'] ?? 0), 2), 'expires_in_days' => (int) ($c['expires_in_days'] ?? 7), 'minimum_amount' => is_numeric($c['minimum_amount'] ?? null) ? round((float) $c['minimum_amount'], 2) : null];
        if ($coupon['enabled']) {
            if ($coupon['kind'] === 'percent' && ($coupon['amount'] <= 0 || $coupon['amount'] > 100)) {
                $coupon['amount'] = min(100.0, max(1.0, $coupon['amount']));
                $warnings[] = __('El porcentaje del cupón se ajustó a :n %.', ['n' => Fmt::number($coupon['amount'])]);
            } elseif ($coupon['kind'] === 'fixed_cart' && $coupon['amount'] <= 0) {
                $problems['coupon'] = __('El cupón de monto fijo necesita un monto mayor que 0.');
            }
            if ($coupon['expires_in_days'] < 1 || $coupon['expires_in_days'] > 90) {
                $coupon['expires_in_days'] = max(1, min(90, $coupon['expires_in_days']));
                $warnings[] = __('La vigencia del cupón se ajustó a :n días.', ['n' => $coupon['expires_in_days']]);
            }
            $warnings[] = __('Las campañas todavía no crean un cupón por persona: crea en WooCommerce el cupón con estas reglas o usa un flujo con el paso «Cupón».');
        }
        $copyFacts = ['pedido' => $prompt, 'cupon' => $coupon];

        // Canales y textos.
        $channels = [];
        $layouts = array_column($this->catalog->emailLayouts(), null, 'key');
        foreach (array_values((array) ($raw['channels'] ?? [])) as $i => $ch) {
            if (! is_array($ch) || ! in_array($ch['channel'] ?? null, Campaign::CHANNELS, true)) {
                continue;
            }
            $channel = (string) $ch['channel'];
            if (isset($channels[$channel])) {
                continue;
            }
            if ($active !== [] && ! in_array($channel, $active, true)) {
                $warnings[] = __('Se quitó :channel: no está conectado.', ['channel' => self::channelLabel($channel)]);

                continue;
            }
            $templates = array_column($this->catalog->templates($workspace, $channel), null, 'id');
            $templateId = is_string($ch['template_id'] ?? null) && $ch['template_id'] !== '' ? $ch['template_id'] : null;
            $libraryKey = is_string($ch['library_key'] ?? null) && $ch['library_key'] !== '' ? $ch['library_key'] : null;
            $entry = ['channel' => $channel, 'template' => ['mode' => 'existing', 'template_id' => null, 'library_key' => null, 'name' => null, 'copy' => []]];
            if ($channel === 'whatsapp') {
                $tpl = $templateId !== null ? ($templates[$templateId] ?? null) : null;
                if ($tpl === null || ($tpl['approved'] ?? false) !== true) {
                    $hasApproved = collect($templates)->contains(fn ($t) => ($t['approved'] ?? false) === true);
                    if (! $final && $hasApproved) {
                        $problems["channels.{$i}"] = __('WhatsApp de marketing necesita una plantilla aprobada por Meta: elige un template_id con "approved": true.');
                    } else {
                        $warnings[] = __('Se quitó WhatsApp: no hay una plantilla aprobada por Meta. Créala en Plantillas y envíala a aprobación.');
                    }

                    continue;
                }
                $entry['template'] = ['mode' => 'existing', 'template_id' => $templateId, 'library_key' => null, 'name' => $tpl['name'], 'copy' => []];
            } elseif ($channel === 'email' && $templateId === null && $libraryKey !== null) {
                if (! isset($layouts[$libraryKey])) {
                    $problems["channels.{$i}.library_key"] = __('El diseño «:key» no existe en la biblioteca de correo.', ['key' => $libraryKey]);

                    continue;
                }
                $copy = [];
                foreach ($layouts[$libraryKey]['slots'] as $slot) {
                    $value = is_string($ch[$slot] ?? null) ? trim(mb_substr($ch[$slot], 0, $slot === 'intro' ? 600 : 160)) : '';
                    if ($value === '') {
                        continue;
                    }
                    $check = $this->numbers->validate($value, $copyFacts);
                    if (! $check->ok()) {
                        if ($strictCopy && ! $final) {
                            $problems["channels.{$i}.{$slot}"] = __('En «:slot» hay cifras que no están en el pedido: :n. Quítalas o usa las del pedido.', ['slot' => $slot, 'n' => implode(', ', $check->unknown)]);
                        } else {
                            $warnings[] = __('Se quitó el texto «:slot» porque tenía cifras que no están en el pedido (:n).', ['slot' => $slot, 'n' => implode(', ', $check->unknown)]);

                            continue;
                        }
                    }
                    $copy[$slot] = $value;
                }
                $entry['template'] = ['mode' => 'library', 'template_id' => null, 'library_key' => $libraryKey, 'name' => $layouts[$libraryKey]['name'], 'copy' => $copy];
            } else {
                $tpl = $templateId !== null ? ($templates[$templateId] ?? null) : null;
                if ($tpl === null) {
                    if ($channel === 'sms' || $final) {
                        $warnings[] = __('Se quitó :channel: no se eligió una plantilla que exista.', ['channel' => self::channelLabel($channel)]);

                        continue;
                    }
                    $problems["channels.{$i}.template_id"] = __('La plantilla de :channel «:id» no existe: usa list_templates.', ['channel' => self::channelLabel($channel), 'id' => (string) $templateId]);

                    continue;
                }
                $entry['template'] = ['mode' => 'existing', 'template_id' => $templateId, 'library_key' => null, 'name' => $tpl['name'], 'copy' => []];
            }
            $channels[$channel] = $entry;
        }
        if ($channels === [] && ! isset($problems['channels'])) {
            $problems['channels'] = __('La campaña necesita al menos un canal conectado con su plantilla.');
        }

        // Horario.
        $s = (array) ($raw['schedule'] ?? []);
        $mode = in_array($s['send_mode'] ?? null, ['scheduled', 'contact_timezone', 'best_time'], true) ? (string) $s['send_mode'] : 'scheduled';
        [$at, $note] = $this->schedule($workspace, (string) ($s['date'] ?? ''), (int) ($s['hour'] ?? 10), $mode);
        $local = $at->setTimezone($tz);

        $holdout = max(0, min(50, (int) ($raw['holdout_pct'] ?? 0)));

        return [[
            'name' => $name,
            'segment' => $segment,
            'channels' => array_values($channels),
            'schedule' => ['send_mode' => $mode, 'send_at' => $at->toIso8601String(), 'date' => $local->toDateString(), 'hour' => (int) $local->format('G'),
                'local' => $local->format('Y-m-d H:i'), 'timezone' => $tz, 'note' => $note, 'reason' => is_string($s['reason'] ?? null) ? mb_substr($s['reason'], 0, 300) : null],
            'coupon' => $coupon,
            'holdout_pct' => $holdout,
        ], $problems, array_values(array_unique($warnings))];
    }

    /**
     * Fecha y hora propuestas en la hora de la tienda, movidas al siguiente momento
     * permitido por la ventana legal del país (nunca domingos, festivos ni fuera de
     * horario en Colombia) y siempre en el futuro.
     *
     * @return array{0: CarbonImmutable, 1: string|null} instante UTC y la nota si se movió
     */
    private function schedule(Workspace $workspace, string $date, int $hour, string $mode): array
    {
        $tz = $workspace->timezone ?: 'America/Bogota';
        $country = strtoupper($workspace->country ?: 'CO');
        $now = CarbonImmutable::now($tz);
        $hour = max(0, min(23, $hour));
        try {
            $wanted = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? new CarbonImmutable("{$date} {$hour}:00:00", $tz) : $now->addDay()->setTime($hour, 0);
        } catch (\Throwable) {
            $wanted = $now->addDay()->setTime($hour, 0);
        }
        $note = null;
        if ($wanted->lessThan($now->addMinutes(30))) {
            $wanted = $now->addDay()->setTime($hour, 0);
            $note = (string) __('La fecha propuesta ya pasó: se movió al día siguiente.');
        }
        $reason = LegalWindow::closedReason($country, $wanted->utc(), $tz);
        $at = LegalWindow::nextOpen($country, $wanted->utc(), $tz);
        if ($reason !== null) {
            $local = CarbonImmutable::instance($at)->setTimezone($tz);
            $note = (string) __('Se movió al :when porque la hora propuesta cae en :reason.', [
                'when' => Fmt::dateTime($local),
                'reason' => mb_strtolower(PolicyDecision::label($reason)),
            ]);
        }

        return [$at, $note];
    }

    /**
     * Tamaño del segmento y revisión (costo y cumplimiento) de cada canal.
     *
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function measure(Workspace $workspace, array $proposal): array
    {
        $segment = $proposal['segment'];
        if ($segment['mode'] === 'existing') {
            $model = Segment::findByPublicId((string) $segment['segment_id']);
            $proposal['segment']['members'] = (int) ($model->member_count ?? 0);
            $proposal['segment']['reach'] = array_map('intval', (array) ($model !== null ? $model->channel_counts : []));
        } else {
            $r = $this->preview->handle($workspace, (array) $segment['definition'], false, 0);
            $proposal['segment']['members'] = (int) ($r['count'] ?? 0);
            $proposal['segment']['reach'] = array_map('intval', (array) ($r['channels'] ?? []));
        }
        $at = CarbonImmutable::parse((string) $proposal['schedule']['send_at']);
        foreach ($proposal['channels'] as $i => $channel) {
            $review = $this->dryRun->run($workspace, $channel['channel'], $segment['mode'] === 'existing' ? (string) $segment['segment_id'] : null,
                $segment['mode'] === 'new' ? (array) $segment['definition'] : null, (string) $proposal['schedule']['send_mode'], $at, (int) $proposal['holdout_pct']);
            $proposal['channels'][$i]['review'] = CampaignDryRun::summary($review);
        }

        return $proposal;
    }

    /**
     * Resumen narrativo con cifras validadas contra los hechos de la propuesta.
     *
     * @param  array<string, mixed>  $proposal
     * @return array{text: string, source: string, validation: array<string, mixed>}
     */
    private function summarize(Workspace $workspace, array $proposal, string $prompt): array
    {
        $facts = self::facts($proposal) + ['pedido' => $prompt];
        $result = $this->writer->write(
            AiRequest::ask($workspace->id, 'copilot', CopilotPrompts::SUMMARY, 'Hechos: '.json_encode($facts, JSON_UNESCAPED_UNICODE)),
            $facts, fn () => $this->fallbackSummary($proposal));

        return ['text' => $result->text, 'source' => $result->source, 'validation' => $result->validation];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    public static function facts(array $proposal): array
    {
        return [
            'campana' => $proposal['name'],
            'segmento' => ['nombre' => $proposal['segment']['name'] ?? null, 'contactos' => $proposal['segment']['members'] ?? null, 'alcance' => $proposal['segment']['reach'] ?? []],
            'canales' => array_map(fn (array $c) => ['canal' => $c['channel'], 'plantilla' => $c['template']['name'] ?? null] + (array) ($c['review'] ?? []), $proposal['channels']),
            'envio' => ['fecha' => $proposal['schedule']['date'], 'hora' => $proposal['schedule']['hour'], 'modo' => $proposal['schedule']['send_mode']],
            'cupon' => $proposal['coupon']['enabled'] ? $proposal['coupon'] : null,
            'grupo_de_control_pct' => $proposal['holdout_pct'],
        ];
    }

    /** @param array<string, mixed> $proposal */
    private function fallbackSummary(array $proposal): string
    {
        $parts = [__('Campaña «:name» para «:segment» (:n contactos).', [
            'name' => $proposal['name'], 'segment' => $proposal['segment']['name'] ?? '', 'n' => Fmt::number((float) ($proposal['segment']['members'] ?? 0)),
        ])];
        foreach ($proposal['channels'] as $c) {
            $review = (array) ($c['review'] ?? []);
            $parts[] = __(':channel: llegaría a :n personas:cost.', [
                'channel' => self::channelLabel($c['channel']), 'n' => Fmt::number((float) ($review['will_send'] ?? 0)),
                'cost' => isset($review['cost_local']) && (float) $review['cost_local'] > 0 ? ', '.__('costo estimado :cost', ['cost' => Fmt::money((float) $review['cost_local'], (string) ($review['currency'] ?? ''))]) : '',
            ]);
        }
        $parts[] = __('Envío: :when.', ['when' => (string) $proposal['schedule']['local']]);

        return implode(' ', $parts);
    }

    /**
     * La propuesta normalizada de vuelta a la forma que devuelve el modelo (para
     * editarla y volver a revisarla).
     *
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    public static function toRaw(array $proposal): array
    {
        $segment = (array) ($proposal['segment'] ?? []);

        return [
            'name' => $proposal['name'] ?? '',
            'segment' => ['mode' => $segment['mode'] ?? 'existing', 'segment_id' => $segment['segment_id'] ?? null, 'name' => $segment['name'] ?? null,
                'definition_json' => isset($segment['definition']) ? json_encode($segment['definition']) : null],
            'channels' => array_map(fn (array $c) => ['channel' => $c['channel'], 'template_id' => $c['template']['template_id'] ?? null, 'library_key' => $c['template']['library_key'] ?? null]
                + array_replace(array_fill_keys(CopilotCatalog::COPY_SLOTS, null), (array) ($c['template']['copy'] ?? [])), (array) ($proposal['channels'] ?? [])),
            'schedule' => ['date' => $proposal['schedule']['date'] ?? '', 'hour' => $proposal['schedule']['hour'] ?? 10, 'send_mode' => $proposal['schedule']['send_mode'] ?? 'scheduled', 'reason' => $proposal['schedule']['reason'] ?? null],
            'coupon' => (array) ($proposal['coupon'] ?? []),
            'holdout_pct' => $proposal['holdout_pct'] ?? 0,
        ];
    }

    public static function channelLabel(string $channel): string
    {
        return match ($channel) {
            'whatsapp' => 'WhatsApp', 'sms' => 'SMS', default => __('Correo'),
        };
    }
}
