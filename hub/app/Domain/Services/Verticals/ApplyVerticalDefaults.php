<?php

declare(strict_types=1);

namespace App\Domain\Services\Verticals;

use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deja los valores por defecto del vertical elegido (modulos.md M18 «Verticales»). Idempotente: se puede llamar
 * cada vez que se elige o se vuelve a elegir el vertical.
 *
 * - **Embudo de leads:** si el espacio de trabajo no tiene pipeline, crea el del vertical como principal. Si ya tiene
 *   uno principal de fábrica (el simple o el de otro vertical, sin cambios del usuario), lo cambia por el del
 *   vertical, salvo que haya leads en etapas que el nuevo no tiene (`kept_in_use`). Un pipeline que el usuario
 *   personalizó no se toca (`kept_custom`).
 * - **Industria de las plantillas:** si está vacía, la del vertical (la copia de la biblioteca sale en su idioma).
 * - Deja la marca `settings.vertical_defaults` ({vertical, pipeline, industry, applied_at}) y la auditoría.
 *
 * No guarda `vertical` ni `business_type` (los guarda el asistente) ni activa estrategias.
 */
final class ApplyVerticalDefaults
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** @return array{vertical: string, pipeline: string, pipeline_id: string|null, industry: string} */
    public function handle(Workspace $workspace, string $vertical): array
    {
        if (! VerticalCatalog::exists($vertical)) {
            throw new InvalidArgumentException(__('Ese tipo de negocio no existe.'));
        }

        return $this->current->run($workspace, function () use ($workspace, $vertical): array {
            return DB::transaction(function () use ($workspace, $vertical): array {
                [$pipeline, $pipelineId] = $this->pipeline($workspace, $vertical);
                $industry = $this->industry($workspace, $vertical);
                $result = ['vertical' => $vertical, 'pipeline' => $pipeline, 'pipeline_id' => $pipelineId, 'industry' => $industry];

                $settings = (array) ($workspace->settings ?? []);
                $previous = (array) ($settings['vertical_defaults'] ?? []);
                $settings['vertical_defaults'] = ['vertical' => $vertical, 'pipeline' => $pipeline, 'industry' => $industry, 'applied_at' => now()->toIso8601String()];
                $workspace->forceFill(['settings' => $settings])->save();
                if (($previous['vertical'] ?? null) !== $vertical || in_array($pipeline, ['created', 'updated'], true) || $industry === 'set') {
                    $this->audit->handle('workspace.vertical_defaults_applied', $workspace, ['vertical' => $vertical, 'pipeline' => $pipeline, 'industry' => $industry], $workspace->id);
                }

                return $result;
            });
        });
    }

    /** @return array{0: string, 1: string|null} resultado y ID público del pipeline */
    private function pipeline(Workspace $workspace, string $vertical): array
    {
        $stages = VerticalCatalog::stages($vertical);
        if ($stages === null) {
            return ['not_applicable', null];
        }
        $name = (string) VerticalCatalog::pipelineName($vertical);
        /** @var LeadPipeline|null $default */
        $default = LeadPipeline::query()->where('is_default', true)->orderBy('created_at')->lockForUpdate()->first()
            ?? LeadPipeline::query()->orderBy('created_at')->lockForUpdate()->first();
        if ($default === null) {
            $created = LeadPipeline::query()->create(['workspace_id' => $workspace->id, 'name' => $name, 'stages' => $stages, 'is_default' => true]);

            return ['created', $created->publicId()];
        }
        if (self::sameStages($default->stages, $stages)) {
            return ['unchanged', $default->publicId()];
        }
        if (! self::isPreset($default->stages)) {
            return ['kept_custom', $default->publicId()];
        }
        // Un lead en una etapa que el embudo nuevo no tiene quedaría perdido: se deja el que está.
        $keys = array_column($stages, 'key');
        $used = Lead::query()->where(fn ($q) => $q->where('pipeline_id', $default->id)->orWhereNull('pipeline_id'))
            ->distinct()->pluck('stage')->map(fn ($v) => (string) $v)->all();
        if (array_diff($used, $keys) !== []) {
            return ['kept_in_use', $default->publicId()];
        }
        $presetName = false;
        foreach (VerticalCatalog::presets() as $preset) {
            $presetName = $presetName || in_array($default->name, $preset['name'], true);
        }
        $default->forceFill(['stages' => $stages, 'is_default' => true] + ($presetName ? ['name' => $name] : []))->save();

        return ['updated', $default->publicId()];
    }

    private function industry(Workspace $workspace, string $vertical): string
    {
        $industry = VerticalCatalog::industry($vertical);
        $current = $workspace->getAttributes()['industry'] ?? null;
        if ($industry === null) {
            return 'not_applicable';
        }
        if (is_string($current) && $current !== '') {
            return $current === $industry ? 'unchanged' : 'kept';
        }
        $workspace->forceFill(['industry' => $industry]);

        return 'set';
    }

    /** @param list<array{key: string, label: string, kind: string}> $stages */
    public static function isPreset(array $stages): bool
    {
        foreach (VerticalCatalog::presets() as $preset) {
            foreach ($preset['stages'] as $candidate) {
                if (self::sameStages($stages, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{key: string, label: string, kind: string}>  $a
     * @param  list<array{key: string, label: string, kind: string}>  $b
     */
    private static function sameStages(array $a, array $b): bool
    {
        $norm = fn (array $stages) => array_map(fn (array $s) => [(string) ($s['key'] ?? ''), trim((string) ($s['label'] ?? '')), (string) ($s['kind'] ?? '')], array_values($stages));

        return $norm($a) === $norm($b);
    }
}
