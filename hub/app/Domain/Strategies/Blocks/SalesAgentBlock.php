<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Intelligence\Ai\Agent\Http\AgentSettingsController;
use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\Requirements;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * El agente de ventas con IA de la bandeja (M11, inteligencia.md §6.7). Dos estrategias lo usan con distinto horario:
 * `always` (atiende todo el día) y `out_of_hours` (solo fuera del horario del equipo, lunes a viernes de 8:00 a 18:00
 * por defecto: dentro de él pasa la conversación a las personas). Activar lo enciende en automático para las
 * conversaciones nuevas; el agente nunca inventa precios ni stock (ReplyGuard) y no hace marketing.
 */
final class SalesAgentBlock implements StrategyBlock
{
    public const ALWAYS = 'always';

    public const OUT_OF_HOURS = 'out_of_hours';

    public function __construct(public readonly string $hours) {}

    public function kind(): string
    {
        return 'sales_agent';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $settings = Requirements::agent($ctx);
        if (! Requirements::agentOn($ctx) || self::hoursMode($settings) !== $this->hours) {
            return BlockState::off('bandeja/agente');
        }
        $problems = [];
        if (! $ctx->aiAvailable()) {
            $problems[] = 'ai_unavailable';
        }
        if (($problem = $ctx->channelProblem(['whatsapp'])) !== null) {
            $problems[] = $problem;
        }

        return BlockState::on('bandeja/agente', $problems);
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $settings = AgentSettings::current($ctx->id());
        if ($settings->enabled && $settings->default_mode !== 'off' && self::hoursMode($settings) === $this->hours) {
            return [];
        }
        $before = $settings->exists ? AgentSettingsController::present($settings) : null;
        $handoff = array_replace(AgentSettings::defaultHandoff(), (array) ($settings->handoff_rules ?? []));
        $hours = (array) ($handoff['hours'] ?? []);
        $handoff['hours'] = $this->hours === self::OUT_OF_HOURS
            ? ['mode' => 'out_of_hours', 'days' => $hours['days'] ?? [1, 2, 3, 4, 5], 'start' => $hours['start'] ?? '08:00', 'end' => $hours['end'] ?? '18:00']
            : ['mode' => 'always'] + $hours;
        $settings->fill([
            'workspace_id' => $ctx->id(), 'enabled' => true,
            // Fuera de horario no hay quién apruebe una sugerencia: responde solo. Todo el día, se respeta un «asistir» ya elegido.
            'default_mode' => $this->hours === self::ALWAYS && $settings->default_mode === 'assist' ? 'assist' : 'auto',
            'handoff_rules' => $handoff, 'updated_by' => $userId,
        ])->save();
        app(RecordAuditLog::class)->handle('agent.settings_updated', null, ['before' => $before, 'after' => AgentSettingsController::present($settings)], $ctx->id(), null, $userId);

        return [];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        $settings = AgentSettings::current($ctx->id());
        if ($settings->exists && $settings->enabled && self::hoursMode($settings) === $this->hours) {
            $before = AgentSettingsController::present($settings);
            $settings->fill(['enabled' => false, 'updated_by' => $userId])->save();
            app(RecordAuditLog::class)->handle('agent.settings_updated', null, ['before' => $before, 'after' => AgentSettingsController::present($settings)], $ctx->id(), null, $userId);
        }

        return [];
    }

    public function preview(StrategyContext $ctx): array
    {
        return Previews::summary([
            __('strategies.preview.agent_'.$this->hours),
            __('strategies.preview.agent_facts'),
            __('strategies.preview.agent_handoff'),
        ]);
    }

    public static function hoursMode(AgentSettings $settings): string
    {
        return (($settings->handoff_rules ?? [])['hours']['mode'] ?? self::ALWAYS) === 'out_of_hours' ? self::OUT_OF_HOURS : self::ALWAYS;
    }
}
