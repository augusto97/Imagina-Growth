<?php

declare(strict_types=1);

namespace App\Domain\Agency\Portable;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Flows\Models\Flow;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Segments\Models\Segment;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Validation\ValidationException;

/**
 * Convierte un flujo, segmento, widget, plantilla o campaña en una definición
 * portable (M22 «Plantillas maestras»): sin IDs del espacio de trabajo de
 * origen, sin contactos ni datos personales.
 *
 * - Las plantillas de correo o SMS que usa se incrustan (`templates`).
 * - Los segmentos se referencian por `system_key` (los del sistema), por nombre
 *   (los estáticos: sus miembros son contactos y no viajan) o se incrustan con
 *   su definición (los dinámicos).
 * - Cualquier otro ID (flujos, campañas, sitios, productos internos…) se quita y
 *   queda una advertencia para revisarlo en el destino.
 *
 * Formato: {format, kind, name, description, item, templates, segments, warnings}.
 * Cada referencia queda como `{"$ref": "template"|"segment", "key": "t1", "form": "public"|"raw"}`.
 */
final class PortableExporter
{
    public const FORMAT = 'igr.portable/1';

    public const KINDS = ['flow', 'segment', 'widget', 'template', 'campaign'];

    private const PREFIXED = '/^([a-z]{1,5})_([0-9A-HJKMNP-TV-Z]{26})$/';

    private const EMAIL = '/^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/';

    /** @var array<string, array<string, mixed>> */
    private array $templates = [];

    /** @var array<string, array<string, mixed>> */
    private array $segments = [];

    /** @var array<string, string> ULID → clave del paquete */
    private array $keys = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * @return array{format: string, kind: string, name: string, description: string|null, item: array<string, mixed>, templates: array<string, array<string, mixed>>, segments: array<string, array<string, mixed>>, warnings: list<string>}
     */
    public function export(Workspace $workspace, string $kind, string $publicId): array
    {
        $this->templates = $this->segments = $this->keys = [];
        $this->warnings = [];

        /** @var array{name: string, description: string|null, item: array<string, mixed>} $data */
        $data = $this->current->run($workspace, fn (): array => match ($kind) {
            'flow' => $this->flow($publicId),
            'segment' => $this->segment($publicId),
            'widget' => $this->widget($publicId),
            'template' => $this->template($publicId),
            'campaign' => $this->campaign($publicId),
            default => throw ValidationException::withMessages(['kind' => __('Tipo de plantilla desconocido.')]),
        });

        return [
            'format' => self::FORMAT,
            'kind' => $kind,
            'name' => $data['name'],
            'description' => $data['description'],
            'item' => $data['item'],
            'templates' => $this->templates,
            'segments' => $this->segments,
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    /** @return array{name: string, description: string|null, item: array<string, mixed>} */
    private function flow(string $publicId): array
    {
        $flow = Flow::findByPublicId($publicId) ?? $this->missing();
        $version = $flow->draftVersion() ?? $flow->currentVersion();
        if ($flow->exclusions) {
            $this->warnings[] = __('Las exclusiones entre flujos no se copian: elígelas de nuevo en el destino.');
        }

        return ['name' => $flow->name, 'description' => $flow->description, 'item' => [
            'name' => $flow->name,
            'description' => $flow->description,
            'template_key' => $flow->template_key,
            'trigger' => $this->walk((array) $flow->trigger, 'trigger'),
            'goal' => $flow->goal === null ? null : $this->walk((array) $flow->goal, 'goal'),
            'reentry_policy' => (array) $flow->reentry_policy,
            'holdout_pct' => (int) $flow->holdout_pct,
            'graph' => $this->walk((array) ($version->graph ?? []), 'graph'),
        ]];
    }

    /** @return array{name: string, description: string|null, item: array<string, mixed>} */
    private function segment(string $publicId): array
    {
        $segment = Segment::findByPublicId($publicId) ?? $this->missing();
        if (! $segment->isDynamic()) {
            throw ValidationException::withMessages(['id' => __('Los segmentos estáticos no se copian: sus miembros son contactos.')]);
        }
        $this->keys[$segment->id] = 'self';

        return ['name' => $segment->name, 'description' => $segment->description, 'item' => [
            'name' => $segment->name,
            'description' => $segment->description,
            'folder' => $segment->folder,
            'definition' => $this->scrub($this->walk((array) $segment->definition, 'definition'), 'definition'),
        ]];
    }

    /** @return array{name: string, description: string|null, item: array<string, mixed>} */
    private function widget(string $publicId): array
    {
        $widget = OnsiteWidget::findByPublicId($publicId) ?? $this->missing();
        $quiz = $widget->quiz;
        if ($widget->coupon_config) {
            $this->warnings[] = __('Revisa la configuración del cupón del widget en el destino.');
        }

        return ['name' => $widget->name, 'description' => null, 'item' => [
            'name' => $widget->name,
            'kind' => $widget->kind,
            'priority' => $widget->priority,
            'targeting' => $this->walk((array) $widget->targeting, 'targeting'),
            'design' => $this->walk((array) $widget->design, 'design'),
            'content' => $this->walk((array) $widget->content, 'content'),
            'variants' => $this->walk((array) $widget->variants, 'variants'),
            'consent_config' => $this->walk((array) $widget->consent_config, 'consent_config'),
            'coupon_config' => $this->walk((array) $widget->coupon_config, 'coupon_config'),
            'quiz' => $quiz === null ? null : [
                'questions' => $this->walk((array) $quiz->questions, 'quiz.questions'),
                'outcomes' => $this->walk((array) $quiz->outcomes, 'quiz.outcomes'),
            ],
        ]];
    }

    /** @return array{name: string, description: string|null, item: array<string, mixed>} */
    private function template(string $publicId): array
    {
        $id = PrefixedId::decode(Template::ID_PREFIX, $publicId) ?? $this->missing();
        $template = Template::query()->find($id) ?? $this->missing();

        return ['name' => $template->name, 'description' => null, 'item' => $this->templateData($template)];
    }

    /** @return array{name: string, description: string|null, item: array<string, mixed>} */
    private function campaign(string $publicId): array
    {
        $campaign = Campaign::findByPublicId($publicId) ?? $this->missing();
        $audience = (array) $campaign->audience;
        $variants = (array) $campaign->variants;
        foreach ($variants as $i => $variant) {
            if (is_array($variant) && is_string($variant['template_id'] ?? null) && PrefixedId::isUlid($variant['template_id'])) {
                $variants[$i]['template_id'] = $this->templateRef($variant['template_id'], 'raw');
            }
        }

        return ['name' => $campaign->name, 'description' => null, 'item' => [
            'name' => $campaign->name,
            'channel' => $campaign->channel,
            'audience' => $this->scrub($this->walk($audience, 'audience'), 'audience'),
            'template_id' => is_string($campaign->template_id) ? $this->templateRef($campaign->template_id, 'raw') : null,
            'variants' => $this->walk($variants, 'variants'),
            'ab_config' => $this->walk((array) $campaign->ab_config, 'ab_config'),
            'holdout_pct' => (int) $campaign->holdout_pct,
            'send_mode' => $campaign->send_mode === 'scheduled' ? 'now' : $campaign->send_mode,
        ]];
    }

    /**
     * Recorre un JSON y cambia los IDs del espacio de trabajo por referencias
     * portables o los quita.
     */
    private function walk(mixed $value, string $path, ?string $key = null): mixed
    {
        if (is_array($value)) {
            if (isset($value['$ref'])) {
                return $value; // ya es una referencia portable
            }
            $isList = array_is_list($value);
            $out = [];
            foreach ($value as $k => $v) {
                $walked = $this->walk($v, $path.'.'.$k, is_string($k) ? $k : $key);
                if ($isList && $walked === null && $v !== null) {
                    continue; // un ID quitado de una lista
                }
                $out[$k] = $walked;
            }

            return $isList ? array_values($out) : $out;
        }
        if (! is_string($value)) {
            return $value;
        }
        if (preg_match(self::PREFIXED, $value, $m) === 1) {
            return match ($m[1]) {
                Template::ID_PREFIX => $this->templateRef($m[2], 'public') ?? $this->drop($path),
                Segment::ID_PREFIX => $this->segmentRef($m[2]) ?? $this->drop($path),
                default => $this->drop($path),
            };
        }
        if (PrefixedId::isUlid($value)) {
            return $key === 'template_id' ? ($this->templateRef($value, 'raw') ?? $this->drop($path)) : $this->drop($path);
        }

        return $value;
    }

    /** @return array{"$ref": string, key: string, form: string}|null */
    private function templateRef(string $ulid, string $form): ?array
    {
        if (! isset($this->keys[$ulid])) {
            $template = Template::query()->find($ulid);
            if ($template === null) {
                return null;
            }
            $key = 't'.(count($this->templates) + 1);
            $this->keys[$ulid] = $key;
            $this->templates[$key] = $this->templateData($template);
        }

        return ['$ref' => 'template', 'key' => $this->keys[$ulid], 'form' => $form];
    }

    /** @return array<string, mixed> */
    private function templateData(Template $template): array
    {
        if ($template->channel === 'whatsapp') {
            $this->warnings[] = __('La plantilla de WhatsApp «:name» debe enlazarse a una plantilla aprobada por Meta en el destino.', ['name' => $template->name]);
        }

        return [
            'channel' => $template->channel,
            'name' => $template->name,
            'category' => $template->category,
            'subject' => $template->subject,
            'preheader' => $template->preheader,
            'body_mjml' => $template->body_mjml,
            'body_html' => $template->body_html,
            'body_text' => $template->body_text,
            'editor_state' => $template->editor_state,
            'variables' => $template->variables,
            'library_key' => $template->library_key,
            'status' => $template->status === 'archived' ? 'draft' : $template->status,
        ];
    }

    /** @return array{"$ref": string, key: string, form: string}|null */
    private function segmentRef(string $ulid): ?array
    {
        if (($this->keys[$ulid] ?? null) === 'self') {
            return null;
        }
        if (! isset($this->keys[$ulid])) {
            $segment = Segment::query()->find($ulid);
            if ($segment === null) {
                return null;
            }
            $key = 's'.(count($this->segments) + 1);
            $this->keys[$ulid] = $key;
            $this->segments[$key] = ['name' => $segment->name, 'kind' => $segment->kind, 'system_key' => $segment->system_key];
            if (! $segment->is_system && $segment->isDynamic()) {
                $this->segments[$key] += [
                    'description' => $segment->description,
                    'folder' => $segment->folder,
                    'definition' => $this->scrub($this->walk((array) $segment->definition, 'segments.'.$key), 'segments.'.$key),
                ];
            } elseif (! $segment->isDynamic()) {
                $this->warnings[] = __('El segmento estático «:name» solo se enlaza si en el destino hay uno con el mismo nombre.', ['name' => $segment->name]);
            }
        }

        return ['$ref' => 'segment', 'key' => $this->keys[$ulid], 'form' => 'public'];
    }

    /**
     * Quita las condiciones o reglas que llevan un correo (no viajan datos
     * personales) y avisa de los productos o categorías, que son del sitio de origen.
     */
    private function scrub(mixed $value, string $path): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            if (is_string($v) && preg_match(self::EMAIL, $v) === 1) {
                $this->warnings[] = __('Se quitó una condición con un correo (:path): no se copian datos personales.', ['path' => $path]);

                return null;
            }
            if (in_array($k, ['ids', 'product_ids', 'category_ids'], true) && is_array($v) && $v !== []) {
                $this->warnings[] = __('Revisa los productos o categorías de las condiciones: son del sitio de origen.');
            }
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $clean = $this->scrub($v, $path.'.'.$k);
            if ($isList && $clean === null && $v !== null) {
                continue;
            }
            $out[$k] = $clean;
        }

        return $isList ? array_values($out) : $out;
    }

    private function drop(string $path): null
    {
        $this->warnings[] = __('Se quitó una referencia propia del espacio de trabajo de origen (:path). Revísala en el destino.', ['path' => $path]);

        return null;
    }

    private function missing(): never
    {
        abort(404);
    }
}
