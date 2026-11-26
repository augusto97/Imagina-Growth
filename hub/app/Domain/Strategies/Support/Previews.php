<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Support;

use App\Domain\Flows\Library\LibraryAdapter;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Templates\Liquid\LiquidSandbox;
use App\Domain\Templates\Rendering\SampleData;
use App\Domain\Templates\Rendering\TemplateVariables;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La vista previa de una estrategia con lo que de verdad va a salir: los mensajes del flujo de la biblioteca (el correo
 * con la copia de M08 y los datos de ejemplo del país; el WhatsApp con el texto de la plantilla aprobada en la cuenta
 * del cliente, o cuál hace falta), el widget con sus textos por defecto o el resumen de un programa.
 */
final class Previews
{
    private const MAX_MESSAGES = 3;

    /**
     * @param  array<string, mixed>  $definition  definición de FlowLibrary
     * @return array{kind: string, messages: list<array<string, mixed>>}
     */
    public static function flow(StrategyContext $ctx, array $definition, bool $whatsapp): array
    {
        $definition = LibraryAdapter::forWorkspace($definition, $ctx->id(), $whatsapp);
        $messages = [];
        foreach ((array) ($definition['graph']['nodes'] ?? []) as $node) {
            $type = (string) ($node['type'] ?? '');
            $key = $node['config']['template_key'] ?? null;
            if (count($messages) >= self::MAX_MESSAGES) {
                break;
            }
            if ($type === 'send_whatsapp' && is_string($key)) {
                $messages[] = self::whatsapp($ctx, $key);
            } elseif ($type === 'send_email' && is_string($key)) {
                $messages[] = self::email($ctx, $key);
            } elseif (in_array($type, ['lead_followup', 'ai_reactivate'], true)) {
                $messages[] = ['channel' => 'ai', 'template' => null, 'text' => __('strategies.preview.ai_message')];
            }
        }

        return ['kind' => 'messages', 'messages' => $messages];
    }

    /** @return array<string, mixed> el correo de la biblioteca de M08 con los ejemplos del espacio de trabajo */
    public static function email(StrategyContext $ctx, string $key): array
    {
        $entry = TemplateLibrary::entry($key);
        if ($entry === null) {
            return ['channel' => 'email', 'template' => $key];
        }
        $industry = $ctx->workspace->getAttribute('industry');
        $copy = TemplateLibrary::copy($entry, is_string($industry) ? $industry : null);
        $vars = self::vars($ctx);
        $out = ['channel' => 'email', 'template' => $key];
        foreach (['subject', 'preheader', 'headline', 'intro', 'cta'] as $part) {
            $out[$part] = self::render($ctx, (string) ($copy[$part] ?? ''), $vars);
        }

        return $out;
    }

    /** @return array<string, mixed> el texto de la plantilla de WhatsApp enlazada (o cuál falta) */
    public static function whatsapp(StrategyContext $ctx, string $key): array
    {
        $status = $ctx->waTemplates()[$key] ?? null;
        $components = DB::table('templates as t')->join('wa_templates as w', 'w.id', '=', 't.wa_template_id')
            ->where('t.workspace_id', $ctx->id())->where('w.workspace_id', $ctx->id())->where('t.channel', 'whatsapp')
            ->where('t.library_key', $key)->where('t.status', '!=', 'archived')->orderByDesc('t.updated_at')->value('w.components');
        $text = null;
        foreach ((array) (is_string($components) ? json_decode($components, true) : []) as $component) {
            if (is_array($component) && strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                $text = (string) ($component['text'] ?? '');
            }
        }

        return [
            'channel' => 'whatsapp', 'template' => $key, 'template_status' => $status,
            'text' => $text ?? __('strategies.preview.wa_template_needed'),
        ];
    }

    /**
     * @param  array<string, mixed>  $content  textos del widget (WidgetSchema::defaults)
     * @return array<string, mixed>
     */
    public static function widget(string $kind, array $content): array
    {
        return [
            'kind' => 'widget', 'widget_kind' => $kind,
            'title' => (string) ($content['title'] ?? ''), 'body' => (string) ($content['body'] ?? ''), 'cta' => (string) ($content['cta'] ?? ''),
            'fields' => array_values(array_map(fn ($f) => (string) ($f['label'] ?? ''), (array) ($content['fields'] ?? []))),
        ];
    }

    /**
     * Un resumen en frases (programas y ajustes).
     *
     * @param  list<string>  $lines
     * @return array{kind: string, lines: list<string>}
     */
    public static function summary(array $lines): array
    {
        return ['kind' => 'summary', 'lines' => array_values(array_filter($lines, fn ($l) => $l !== ''))];
    }

    /** @return array<string, mixed> */
    private static function vars(StrategyContext $ctx): array
    {
        return $ctx->remember('preview_vars', function () use ($ctx): array {
            $store = TemplateVariables::store($ctx->workspace);

            return SampleData::forWorkspace($ctx->workspace, (string) $store['url']) + ['tienda' => $store];
        });
    }

    /** @param array<string, mixed> $vars */
    private static function render(StrategyContext $ctx, string $source, array $vars): string
    {
        if ($source === '') {
            return '';
        }
        try {
            return trim(app(LiquidSandbox::class)->render($source, $vars, app(TemplateVariables::class)->register($ctx->workspace)));
        } catch (Throwable) {
            return '';
        }
    }
}
