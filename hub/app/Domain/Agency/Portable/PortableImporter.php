<?php

declare(strict_types=1);

namespace App\Domain\Agency\Portable;

use App\Domain\Campaigns\Audience\AudienceQuery;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Flows\Actions\SaveFlow;
use App\Domain\Flows\Support\InvalidFlow;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Models\Quiz;
use App\Domain\Segments\Actions\SaveSegment;
use App\Domain\Segments\Models\Segment;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Crea en un espacio de trabajo lo que describe una definición portable
 * (ver {@see PortableExporter}). Todo llega en borrador: los flujos sin
 * publicar, las campañas sin fecha, los widgets sin sitio. Los segmentos del
 * sistema se enlazan por `system_key`, los demás por nombre o se crean; las
 * plantillas idénticas se reutilizan y las demás se copian.
 */
final class PortableImporter
{
    /** @var array<string, mixed> */
    private array $bundle = [];

    /** @var array<string, string|null> clave del paquete → ULID en el destino */
    private array $resolved = [];

    /** @var list<string> */
    private array $warnings = [];

    private ?User $user = null;

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly SaveSegment $saveSegment,
        private readonly SaveFlow $saveFlow,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{kind: string, id: string, name: string, warnings: list<string>}
     */
    public function import(Workspace $workspace, array $bundle, User $user, ?string $name = null): array
    {
        if (($bundle['format'] ?? null) !== PortableExporter::FORMAT || ! in_array($bundle['kind'] ?? null, PortableExporter::KINDS, true)) {
            throw ValidationException::withMessages(['payload' => __('La plantilla no tiene un formato válido.')]);
        }
        $this->bundle = $bundle;
        $this->resolved = [];
        $this->warnings = array_values(array_filter((array) ($bundle['warnings'] ?? []), 'is_string'));
        $this->user = $user;
        $kind = (string) $bundle['kind'];
        $item = (array) ($bundle['item'] ?? []);
        if ($name !== null && trim($name) !== '') {
            $item['name'] = trim($name);
        }

        /** @var array{id: string, name: string} $created */
        $created = $this->current->run($workspace, fn (): array => DB::transaction(fn (): array => match ($kind) {
            'template' => $this->createTemplate($item),
            'segment' => $this->createSegment($item),
            'flow' => $this->createFlow($item),
            'widget' => $this->createWidget($item),
            default => $this->createCampaign($item),
        }));
        $this->audit->handle('agency.template_applied', null, ['kind' => $kind, 'id' => $created['id'], 'name' => $created['name']], $workspace->id, null, $user->id);

        return ['kind' => $kind, 'id' => $created['id'], 'name' => $created['name'], 'warnings' => array_values(array_unique($this->warnings))];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, name: string}
     */
    private function createTemplate(array $item): array
    {
        $template = $this->copyTemplate($item, reuse: false);

        return ['id' => Template::ID_PREFIX.'_'.$template->id, 'name' => $template->name];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, name: string}
     */
    private function createSegment(array $item): array
    {
        $segment = $this->saveSegment->handle(null, [
            'name' => $this->uniqueSegmentName((string) ($item['name'] ?? __('Segmento'))),
            'description' => $item['description'] ?? null,
            'folder' => $item['folder'] ?? null,
            'definition' => (array) $this->replace($item['definition'] ?? []),
        ], $this->requireUser());

        return ['id' => $segment->publicId(), 'name' => $segment->name];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, name: string}
     */
    private function createFlow(array $item): array
    {
        $data = [
            'name' => (string) ($item['name'] ?? __('Flujo')),
            'description' => $item['description'] ?? null,
            'template_key' => $item['template_key'] ?? null,
            'trigger' => (array) $this->replace($item['trigger'] ?? ['type' => 'manual']),
            'reentry_policy' => (array) ($item['reentry_policy'] ?? ['mode' => 'once']),
            'holdout_pct' => (int) ($item['holdout_pct'] ?? 0),
            'graph' => (array) $this->replace($item['graph'] ?? SaveFlow::emptyGraph()),
        ];
        if (is_array($item['goal'] ?? null)) {
            $data['goal'] = $this->replace($item['goal']);
        }
        try {
            $result = $this->saveFlow->handle(null, $data, $this->requireUser()->id);
        } catch (InvalidFlow|ValidationException) {
            // El disparador o la meta apuntan a algo que no hay en el destino.
            $this->warnings[] = __('El disparador o la meta del flujo no se pudieron enlazar: el flujo queda con disparador manual para que lo elijas.');
            $data['trigger'] = ['type' => 'manual'];
            unset($data['goal']);
            $result = $this->saveFlow->handle(null, $data, $this->requireUser()->id);
        }

        return ['id' => $result['flow']->publicId(), 'name' => $result['flow']->name];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, name: string}
     */
    private function createWidget(array $item): array
    {
        $kind = (string) ($item['kind'] ?? '');
        if (! in_array($kind, OnsiteWidget::KINDS, true)) {
            throw ValidationException::withMessages(['payload' => __('Tipo de widget desconocido.')]);
        }
        $widget = OnsiteWidget::query()->create([
            'workspace_id' => $this->current->id(),
            'site_id' => null,
            'name' => mb_substr((string) ($item['name'] ?? __('Widget')), 0, 160),
            'kind' => $kind,
            'status' => 'draft',
            'priority' => (int) ($item['priority'] ?? 100),
            'targeting' => $this->replace($item['targeting'] ?? null) ?: null,
            'design' => $this->replace($item['design'] ?? null) ?: null,
            'content' => $this->replace($item['content'] ?? null) ?: null,
            'variants' => $this->replace($item['variants'] ?? null) ?: null,
            'consent_config' => $this->replace($item['consent_config'] ?? null) ?: null,
            'coupon_config' => $this->replace($item['coupon_config'] ?? null) ?: null,
            'created_by' => $this->requireUser()->id,
        ]);
        if (is_array($item['quiz'] ?? null)) {
            Quiz::query()->create([
                'workspace_id' => $this->current->id(),
                'widget_id' => $widget->id,
                'questions' => (array) $this->replace($item['quiz']['questions'] ?? []),
                'outcomes' => (array) $this->replace($item['quiz']['outcomes'] ?? []),
            ]);
        }

        return ['id' => $widget->publicId(), 'name' => $widget->name];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, name: string}
     */
    private function createCampaign(array $item): array
    {
        $channel = (string) ($item['channel'] ?? '');
        if (! in_array($channel, Campaign::CHANNELS, true)) {
            throw ValidationException::withMessages(['payload' => __('Canal de campaña desconocido.')]);
        }
        try {
            $audience = AudienceQuery::normalize((array) $this->replace($item['audience'] ?? []));
        } catch (InvalidArgumentException) {
            $audience = AudienceQuery::normalize([]);
            $this->warnings[] = __('La audiencia de la campaña no se pudo copiar: elígela de nuevo.');
        }
        $template = $this->replace($item['template_id'] ?? null);
        $mode = (string) ($item['send_mode'] ?? 'now');
        $campaign = Campaign::query()->create([
            'workspace_id' => $this->current->id(),
            'name' => mb_substr((string) ($item['name'] ?? __('Campaña')), 0, 160),
            'channel' => $channel,
            'status' => Campaign::DRAFT,
            'audience' => $audience,
            'template_id' => is_string($template) ? $template : null,
            'variants' => $this->replace($item['variants'] ?? null) ?: null,
            'ab_config' => $this->replace($item['ab_config'] ?? null) ?: null,
            'holdout_pct' => max(0, min(50, (int) ($item['holdout_pct'] ?? 0))),
            'send_mode' => in_array($mode, Campaign::SEND_MODES, true) && $mode !== 'scheduled' ? $mode : 'now',
            'send_at' => null,
            'created_by' => $this->requireUser()->id,
        ]);

        return ['id' => $campaign->publicId(), 'name' => $campaign->name];
    }

    /** Cambia las referencias portables por los IDs del destino (o las quita). */
    private function replace(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (isset($value['$ref'], $value['key']) && is_string($value['$ref']) && is_string($value['key'])) {
            $ulid = $value['$ref'] === 'template' ? $this->template($value['key']) : $this->segment($value['key']);
            if ($ulid === null) {
                return null;
            }

            return ($value['form'] ?? 'public') === 'raw' ? $ulid : ($value['$ref'] === 'template' ? Template::ID_PREFIX : Segment::ID_PREFIX).'_'.$ulid;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $new = $this->replace($v);
            if ($isList && $new === null && $v !== null) {
                continue;
            }
            $out[$k] = $new;
        }

        return $isList ? array_values($out) : $out;
    }

    private function template(string $key): ?string
    {
        if (array_key_exists('t:'.$key, $this->resolved)) {
            return $this->resolved['t:'.$key];
        }
        $def = $this->bundle['templates'][$key] ?? null;

        return $this->resolved['t:'.$key] = is_array($def) ? $this->copyTemplate($def, reuse: true)->id : null;
    }

    /** @param array<string, mixed> $def */
    private function copyTemplate(array $def, bool $reuse): Template
    {
        $channel = (string) ($def['channel'] ?? '');
        if (! in_array($channel, Template::CHANNELS, true)) {
            throw ValidationException::withMessages(['payload' => __('Canal de plantilla desconocido.')]);
        }
        $fields = [
            'channel' => $channel,
            'name' => mb_substr((string) ($def['name'] ?? __('Plantilla')), 0, 160),
            'category' => in_array($def['category'] ?? null, Template::CATEGORIES, true) ? $def['category'] : 'marketing',
            'subject' => $def['subject'] ?? null,
            'preheader' => $def['preheader'] ?? null,
            'body_mjml' => $def['body_mjml'] ?? null,
            'body_html' => $def['body_html'] ?? null,
            'body_text' => $def['body_text'] ?? null,
        ];
        if ($reuse) {
            $same = Template::query()->where('status', '!=', 'archived');
            foreach ($fields as $column => $v) {
                $v === null ? $same->whereNull($column) : $same->where($column, $v);
            }
            $existing = $same->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        $status = in_array($def['status'] ?? null, ['draft', 'ready'], true) ? $def['status'] : 'draft';

        return Template::query()->create($fields + [
            'workspace_id' => $this->current->id(),
            'editor_state' => $def['editor_state'] ?? null,
            'variables' => is_array($def['variables'] ?? null) ? $def['variables'] : null,
            'library_key' => $def['library_key'] ?? null,
            'wa_template_id' => null, // la aprobada en Meta es de la cuenta de origen
            'status' => $channel === 'whatsapp' ? 'draft' : $status,
            'created_by' => $this->requireUser()->id,
        ]);
    }

    private function segment(string $key): ?string
    {
        if (array_key_exists('s:'.$key, $this->resolved)) {
            return $this->resolved['s:'.$key];
        }
        $this->resolved['s:'.$key] = null; // evita ciclos
        $def = $this->bundle['segments'][$key] ?? null;
        if (! is_array($def)) {
            return null;
        }
        $name = (string) ($def['name'] ?? '');
        if (is_string($def['system_key'] ?? null)) {
            $id = Segment::query()->where('system_key', $def['system_key'])->value('id');
            if (! is_string($id)) {
                $this->warnings[] = __('El segmento del sistema «:name» no existe en el destino.', ['name' => $name]);
            }

            return $this->resolved['s:'.$key] = is_string($id) ? $id : null;
        }
        if (($def['kind'] ?? null) === Segment::KIND_STATIC || ! is_array($def['definition'] ?? null)) {
            $id = Segment::query()->where('name', $name)->where('kind', Segment::KIND_STATIC)->value('id');
            if (! is_string($id)) {
                $this->warnings[] = __('El segmento «:name» no existe en el destino: se quitó de la plantilla.', ['name' => $name]);
            }

            return $this->resolved['s:'.$key] = is_string($id) ? $id : null;
        }
        $definition = (array) $this->replace($def['definition']);
        $same = Segment::query()->where('name', $name)->where('kind', Segment::KIND_DYNAMIC)->get()
            ->first(fn (Segment $s) => json_encode($s->definition) === json_encode($definition));
        if ($same !== null) {
            return $this->resolved['s:'.$key] = $same->id;
        }
        try {
            $segment = $this->saveSegment->handle(null, [
                'name' => $this->uniqueSegmentName($name),
                'description' => $def['description'] ?? null,
                'folder' => $def['folder'] ?? null,
                'definition' => $definition,
            ], $this->requireUser());
        } catch (ValidationException) {
            $this->warnings[] = __('El segmento «:name» no se pudo crear en el destino.', ['name' => $name]);

            return null;
        }

        return $this->resolved['s:'.$key] = $segment->id;
    }

    private function uniqueSegmentName(string $name): string
    {
        $name = mb_substr(trim($name) !== '' ? trim($name) : __('Segmento'), 0, 140);
        if (! Segment::query()->where('name', $name)->exists()) {
            return $name;
        }
        for ($i = 2; $i < 50; $i++) {
            $candidate = $name.' ('.$i.')';
            if (! Segment::query()->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $name.' ('.now()->format('YmdHis').')';
    }

    private function requireUser(): User
    {
        return $this->user ?? throw new InvalidArgumentException('Falta el usuario.');
    }
}
