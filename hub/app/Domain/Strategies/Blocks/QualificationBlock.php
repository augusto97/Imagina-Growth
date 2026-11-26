<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Services\Leads\Models\LeadQualificationSet;
use App\Domain\Services\Leads\Qualification\QualificationRules;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyNotReady;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Calificación en el chat (M18 «Filtra a los curiosos»): el agente de ventas hace las preguntas del dueño de forma
 * natural y guarda cada respuesta con la frase del cliente. Activar prende los juegos de preguntas que ya existen o,
 * si no hay ninguno, crea uno recomendado (qué necesita, para cuándo y dónde) con las reglas de Leads →
 * Calificación (QualificationRules). Necesita el agente encendido.
 */
final class QualificationBlock implements StrategyBlock
{
    public function kind(): string
    {
        return 'qualification';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $sets = $this->sets($ctx);
        if ($sets->contains('active', true)) {
            return BlockState::on('leads?tab=calificacion');
        }

        return $sets->isNotEmpty() ? BlockState::paused('leads?tab=calificacion') : BlockState::off('leads?tab=calificacion');
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $sets = LeadQualificationSet::query()->get();
        if ($sets->contains('active', true)) {
            return [];
        }
        if ($sets->isNotEmpty()) {
            foreach ($sets as $set) {
                $set->forceFill(['active' => true])->save();
                app(RecordAuditLog::class)->handle('leads.qualification_updated', $set, ['active' => true], $ctx->id(), null, $userId);
            }

            return [];
        }
        try {
            $data = QualificationRules::normalize([
                'name' => __('strategies.qualification.name'),
                'questions' => [
                    ['key' => 'need', 'text' => __('strategies.qualification.need'), 'type' => 'text', 'required' => true],
                    ['key' => 'when', 'text' => __('strategies.qualification.when'), 'type' => 'text', 'required' => true],
                    ['key' => 'where', 'text' => __('strategies.qualification.where'), 'type' => 'text', 'required' => false],
                ],
            ]);
        } catch (ValidationException $e) {
            throw new StrategyNotReady(__('strategies.errors.qualification_pending'), [], array_map(fn ($m) => array_values(array_map('strval', (array) $m)), $e->errors()), 'leads?tab=calificacion');
        }
        $set = LeadQualificationSet::query()->create($data + ['workspace_id' => $ctx->id(), 'position' => 1]);
        app(RecordAuditLog::class)->handle('leads.qualification_created', $set, ['name' => $set->name], $ctx->id(), null, $userId);

        return ['qualification_sets' => [$set->id]];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        foreach (LeadQualificationSet::query()->where('active', true)->get() as $set) {
            $set->forceFill(['active' => false])->save();
            app(RecordAuditLog::class)->handle('leads.qualification_updated', $set, ['active' => false], $ctx->id(), null, $userId);
        }

        return [];
    }

    public function preview(StrategyContext $ctx): array
    {
        $first = $this->sets($ctx)->first();
        $questions = $first !== null ? array_map(fn ($q) => (string) ($q['text'] ?? ''), (array) $first->questions)
            : [__('strategies.qualification.need'), __('strategies.qualification.when'), __('strategies.qualification.where')];

        return Previews::summary(array_values(array_slice($questions, 0, 4)));
    }

    /** @return Collection<int, LeadQualificationSet> */
    private function sets(StrategyContext $ctx): Collection
    {
        return $ctx->remember('qualification_sets', fn () => LeadQualificationSet::query()->orderBy('position')->get());
    }
}
