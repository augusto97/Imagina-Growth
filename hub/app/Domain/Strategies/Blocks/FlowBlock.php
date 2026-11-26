<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Flows\Actions\ChangeFlowStatus;
use App\Domain\Flows\Actions\CreateFlowFromLibrary;
use App\Domain\Flows\Actions\PublishFlow;
use App\Domain\Flows\Actions\SaveFlow;
use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Support\InvalidFlow;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\Requirement;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyNotReady;
use Illuminate\Support\Facades\DB;

/**
 * Un flujo de la biblioteca (modulos.md M09). Activar usa lo mismo que «activar en 1 clic» de la biblioteca
 * (CreateFlowFromLibrary, PublishFlow, ChangeFlowStatus); si el flujo ya existe (en pausa o publicado), lo reanuda en
 * lugar de crear otro. Con WhatsApp conectado pero sin las plantillas aprobadas por Meta, y con correo, se crea solo
 * con correo: nunca sale un WhatsApp con una plantilla sin aprobar.
 */
final class FlowBlock implements StrategyBlock
{
    private const BAD_TEMPLATE = ['REJECTED', 'PAUSED', 'DISABLED'];

    /**
     * Lo que se sabe de cada flujo de la biblioteca (si existe y qué canales usa), una vez por proceso:
     * `FlowLibrary::find` arma los 36 grafos en cada llamada (~0,5 ms) y el catálogo lo preguntaba 2 o 3 veces por
     * estrategia en cada petición (unos 50 ms del Inicio y del catálogo).
     *
     * @var array<string, array{available: bool, name: string, uses: array{email: bool, whatsapp: bool, wa_keys: list<string>}}>
     */
    private static array $library = [];

    /** @param bool $optional un flujo de apoyo de la estrategia (no la bloquea si no se puede prender) */
    public function __construct(public readonly string $libraryKey, public readonly bool $optional = false) {}

    public function kind(): string
    {
        return 'flow';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        if ($this->optional) {
            return [];
        }
        $uses = $this->uses();
        $channel = match (true) {
            $uses['email'] && ($uses['whatsapp'] || $uses['wa_keys'] !== []) => 'channel',
            $uses['email'] => 'email',
            default => 'whatsapp',
        };
        $out = [new Requirement($channel, $channel === 'channel' ? $ctx->hasChannel('whatsapp', 'email') : $ctx->hasChannel($channel), true, 'canales')];
        if ($uses['wa_keys'] !== [] && $ctx->hasChannel('whatsapp')) {
            $missing = $this->missingTemplates($ctx);
            $out[] = new Requirement('wa_templates', $missing === [], ! ($uses['email'] && $ctx->hasChannel('email')), 'canales/plantillas-whatsapp',
                ['count' => count($missing), 'templates' => implode(', ', $missing)]);
        }

        return $out;
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $flows = $ctx->flows($this->libraryKey);
        $refs = ['flows' => array_values($flows->pluck('id')->map(fn ($id) => (string) $id)->all())];
        $active = $flows->firstWhere('status', Flow::ACTIVE);
        if ($active !== null) {
            return BlockState::on('flujos/fl_'.$active->id, $this->problems($ctx, (string) $active->id), $refs);
        }
        $paused = $flows->firstWhere('status', Flow::PAUSED);
        if ($paused !== null) {
            return BlockState::paused('flujos/fl_'.$paused->id, $refs);
        }
        $draft = $flows->first();

        return BlockState::off($draft !== null ? 'flujos/fl_'.$draft->id : 'flujos?tab=biblioteca', $refs);
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $flows = $ctx->flows($this->libraryKey);
        $active = $flows->firstWhere('status', Flow::ACTIVE);
        if ($active !== null) {
            return ['flows' => [(string) $active->id]];
        }
        $existing = $flows->firstWhere('status', Flow::PAUSED) ?? $flows->first();
        if ($existing !== null) {
            $flow = Flow::query()->findOrFail((string) $existing->id);
            $this->resume($flow, $userId);

            return ['flows' => [$flow->id]];
        }
        $result = app(CreateFlowFromLibrary::class)->handle($this->libraryKey, true, $userId, $this->useWhatsapp($ctx));
        if (! $result['activated']) {
            throw new StrategyNotReady(__('strategies.errors.flow_pending'), [], $result['issues'], 'flujos/'.$result['flow']->publicId());
        }

        return ['flows' => [$result['flow']->id]];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        foreach ($ctx->flows($this->libraryKey)->where('status', Flow::ACTIVE) as $row) {
            app(ChangeFlowStatus::class)->handle(Flow::query()->findOrFail((string) $row->id), Flow::PAUSED);
        }

        return [];
    }

    public function preview(StrategyContext $ctx): ?array
    {
        $definition = FlowLibrary::find($this->libraryKey);
        if ($definition === null || ! is_array($definition['graph'])) {
            return null;
        }

        return Previews::flow($ctx, $definition, $this->useWhatsapp($ctx));
    }

    /**
     * ¿El flujo existe en la biblioteca? Las estrategias de la biblioteca ampliada (§11.4) se declaran antes de que
     * su flujo exista: quedan «Próximamente» y se activan solas cuando el flujo llega a FlowLibrary.
     */
    public function available(): bool
    {
        return $this->library()['available'];
    }

    /** ¿Se puede prender ya (canal y plantillas)? Lo preguntan los flujos de apoyo, que no bloquean la estrategia. */
    public function ready(StrategyContext $ctx): bool
    {
        foreach ((new self($this->libraryKey))->requirements($ctx) as $requirement) {
            if ($requirement->blocks()) {
                return false;
            }
        }

        return true;
    }

    /** ¿Se crea con los pasos de WhatsApp? Solo con el número conectado y todas sus plantillas aprobadas. */
    public function useWhatsapp(StrategyContext $ctx): bool
    {
        return $ctx->hasChannel('whatsapp') && $this->missingTemplates($ctx) === [];
    }

    /** @return list<string> plantillas de WhatsApp del flujo que Meta todavía no aprobó en esta cuenta */
    public function missingTemplates(StrategyContext $ctx): array
    {
        return array_values(array_filter($this->uses()['wa_keys'], fn (string $k) => ! $ctx->waTemplateApproved($k)));
    }

    /** @return array{email: bool, whatsapp: bool, wa_keys: list<string>} canales que usa el flujo de la biblioteca */
    public function uses(): array
    {
        return $this->library()['uses'];
    }

    /** El nombre del flujo en la biblioteca (lo que la estrategia crea), o la clave si no existe. */
    public function libraryName(): string
    {
        return $this->library()['name'];
    }

    /** @return array{available: bool, name: string, uses: array{email: bool, whatsapp: bool, wa_keys: list<string>}} */
    private function library(): array
    {
        if (self::$library === []) {
            // Toda la biblioteca de una vez: armarla cuesta lo mismo que buscar una sola clave.
            foreach ([...FlowLibrary::all(), ...FlowLibrary::extras()] as $flow) {
                $graph = $flow['graph'] ?? null;
                self::$library[(string) $flow['key']] = ['available' => is_array($graph), 'name' => (string) ($flow['name'] ?? $flow['key']), 'uses' => self::channelsOf(is_array($graph) ? $graph : [])];
            }
        }

        return self::$library[$this->libraryKey] ?? ['available' => false, 'name' => $this->libraryKey, 'uses' => self::channelsOf([])];
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return array{email: bool, whatsapp: bool, wa_keys: list<string>}
     */
    public static function channelsOf(array $graph): array
    {
        $email = false;
        $whatsapp = false;
        $keys = [];
        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            $type = (string) ($node['type'] ?? '');
            $config = (array) ($node['config'] ?? []);
            if ($type === 'send_whatsapp') {
                $whatsapp = true;
                if (is_string($config['template_key'] ?? null)) {
                    $keys[] = $config['template_key'];
                }
            } elseif ($type === 'send_email' || ($type === 'notify_team' && ($config['channel'] ?? 'email') === 'email')) {
                $email = true;
            } elseif (in_array($type, ['channel_split', 'lead_followup', 'ai_reactivate'], true)) {
                $channels = (array) ($config['channels'] ?? []);
                $email = $email || in_array('email', $channels, true);
                $whatsapp = $whatsapp || in_array('whatsapp', $channels, true);
            }
        }

        return ['email' => $email, 'whatsapp' => $whatsapp, 'wa_keys' => array_values(array_unique($keys))];
    }

    /** @throws StrategyNotReady */
    private function resume(Flow $flow, ?string $userId): void
    {
        try {
            if ($flow->current_version_id === null || $flow->draftVersion() !== null && $flow->status === Flow::DRAFT) {
                $issues = app(SaveFlow::class)->issues($flow);
                if ($issues !== []) {
                    throw new StrategyNotReady(__('strategies.errors.flow_pending'), [], $issues, 'flujos/'.$flow->publicId());
                }
                if ($flow->draftVersion() !== null) {
                    app(PublishFlow::class)->handle($flow, $userId);
                }
            }
            app(ChangeFlowStatus::class)->handle($flow->refresh(), Flow::ACTIVE);
        } catch (InvalidFlow $e) {
            throw new StrategyNotReady($e->getMessage(), [], array_map(fn ($m) => is_array($m) ? array_values(array_map('strval', $m)) : [(string) $m], $e->errors), 'flujos/'.$flow->publicId());
        }
    }

    /**
     * Problemas del flujo activo: un canal que usa su versión publicada quedó caído, o Meta rechazó o pausó una de sus
     * plantillas de WhatsApp.
     *
     * @return list<string>
     */
    private function problems(StrategyContext $ctx, string $flowId): array
    {
        $graph = $ctx->remember('flow_graph:'.$flowId, function () use ($ctx, $flowId): array {
            $json = DB::table('flow_versions')->join('flows', 'flows.current_version_id', '=', 'flow_versions.id')
                ->where('flows.workspace_id', $ctx->id())->where('flows.id', $flowId)->value('flow_versions.graph');

            return is_string($json) ? (array) json_decode($json, true) : [];
        });
        $uses = self::channelsOf($graph);
        $channels = [];
        if ($uses['whatsapp'] || $uses['wa_keys'] !== []) {
            $channels[] = 'whatsapp';
        }
        if ($uses['email']) {
            $channels[] = 'email';
        }
        $problems = [];
        if (($problem = $ctx->channelProblem($channels)) !== null) {
            $problems[] = $problem;
        }
        foreach ($uses['wa_keys'] as $key) {
            if (in_array($ctx->waTemplates()[$key] ?? null, self::BAD_TEMPLATE, true)) {
                $problems[] = 'template_rejected';
                break;
            }
        }

        return $problems;
    }
}
