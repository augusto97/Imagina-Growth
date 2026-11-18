<?php

declare(strict_types=1);

namespace App\Domain\Segments\Actions;

use App\Domain\Segments\Definition\DefinitionValidator;
use App\Domain\Segments\Definition\InvalidDefinition;
use App\Domain\Segments\Jobs\RecomputeSegmentJob;
use App\Domain\Segments\Models\Segment;
use App\Domain\Segments\Support\DependencyIndex;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Validation\ValidationException;

/** Crea o actualiza un segmento dinámico y lo manda a recalcular. */
final class SaveSegment
{
    public function __construct(
        private readonly DefinitionValidator $validator,
        private readonly CurrentWorkspace $current,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  array{name?: string, description?: string|null, folder?: string|null, definition?: array<mixed>}  $data
     */
    public function handle(?Segment $segment, array $data, User $user): Segment
    {
        if ($segment !== null && $segment->is_system && isset($data['definition'])) {
            throw ValidationException::withMessages(['definition' => __('Los segmentos del sistema no se pueden editar. Duplícalo para cambiarlo.')]);
        }
        if ($segment !== null && $segment->kind === Segment::KIND_STATIC && isset($data['definition'])) {
            throw ValidationException::withMessages(['definition' => __('Un segmento estático no tiene condiciones.')]);
        }

        $definition = null;
        if (isset($data['definition'])) {
            try {
                $definition = $this->validator->validate($data['definition'], $segment?->id);
            } catch (InvalidDefinition $e) {
                throw $e->toValidationException();
            }
        }

        $isNew = $segment === null;
        $segment ??= new Segment([
            'workspace_id' => $this->current->id(),
            'kind' => Segment::KIND_DYNAMIC,
            'is_system' => false,
            'created_by' => $user->id,
        ]);
        $segment->fill(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'description' => array_key_exists('description', $data) ? ($data['description'] ?? '') : null,
            'folder' => array_key_exists('folder', $data) ? ($data['folder'] ?? '') : null,
        ], fn ($v) => $v !== null));
        if (array_key_exists('description', $data) && $data['description'] === null) {
            $segment->description = null;
        }
        if (array_key_exists('folder', $data) && ($data['folder'] ?? '') === '') {
            $segment->folder = null;
        }
        $definitionChanged = $definition !== null && $definition !== $segment->definition;
        if ($definition !== null) {
            $segment->definition = $definition;
        }
        $segment->save();

        DependencyIndex::forget($this->current->id());
        $this->audit->handle($isNew ? 'segment.created' : 'segment.updated', $segment, ['name' => $segment->name], $this->current->id(), null, $user->id);

        if ($segment->isDynamic() && ($isNew || $definitionChanged)) {
            RecomputeSegmentJob::dispatch($this->current->id(), $segment->id, ! $isNew);
        }

        return $segment;
    }
}
