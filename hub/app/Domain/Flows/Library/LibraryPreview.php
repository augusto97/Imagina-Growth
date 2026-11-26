<?php

declare(strict_types=1);

namespace App\Domain\Flows\Library;

use App\Domain\Templates\Exceptions\TemplateRenderException;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Templates\Library\WhatsAppLibrary;
use App\Domain\Templates\Liquid\LiquidSandbox;
use App\Domain\Templates\Rendering\SampleData;
use App\Domain\Templates\Rendering\TemplateVariables;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Vista previa de un flujo listo antes de crearlo («Nueva automatización» → ver el recorrido): el grafo como queda en
 * este espacio de trabajo (sin los pasos de WhatsApp si no tiene un número conectado, LibraryAdapter) y, por cada
 * envío, el mensaje con los datos de ejemplo de su país (SampleData): asunto, preencabezado y texto del correo de la
 * biblioteca de M08, o el texto propuesto para Meta de las de WhatsApp. Las de WhatsApp que el cliente elige entre las
 * aprobadas (las del flujo 1 al 20) no tienen texto propio: solo su clave.
 */
final class LibraryPreview
{
    public function __construct(private readonly LiquidSandbox $sandbox, private readonly TemplateVariables $variables) {}

    /**
     * @param  array<string, mixed>  $flow  definición de FlowLibrary ya adaptada al espacio de trabajo
     * @return list<array{node_id: string, channel: string, template_key: string, name: string|null, kind: string,
     *     subject: string|null, preheader: string|null, text: string|null, buttons: list<string>, in_library: bool}>
     */
    public function messages(Workspace $workspace, array $flow, ?string $industry = null): array
    {
        $store = TemplateVariables::store($workspace);
        $data = SampleData::forWorkspace($workspace, (string) ($store['url'] ?? '')) + ['tienda' => $store];
        $register = $this->variables->register($workspace);
        $industry ??= is_string($workspace->getAttribute('industry')) ? $workspace->getAttribute('industry') : null;

        $out = [];
        foreach ((array) ($flow['graph']['nodes'] ?? []) as $node) {
            $channel = match ($node['type'] ?? null) {
                'send_whatsapp' => 'whatsapp',
                'send_email' => 'email',
                'send_sms' => 'sms',
                default => null,
            };
            $key = $node['config']['template_key'] ?? null;
            if ($channel === null || ! is_string($key)) {
                continue;
            }
            $entry = TemplateLibrary::entry($key);
            $copy = $entry !== null ? TemplateLibrary::copy($entry, $industry) : [];
            $buttons = [];
            if ($entry !== null && $channel === 'whatsapp') {
                $buttons = array_map(fn (array $b) => (string) ($b['text'] ?? ''), WhatsAppLibrary::compile($entry, (string) ($copy['text'] ?? ''))['buttons']);
            }
            $text = $channel === 'email'
                ? trim(implode("\n\n", array_filter([$copy['headline'] ?? null, $copy['intro'] ?? null, $copy['body'] ?? null], fn ($v) => is_string($v) && $v !== '')))
                : ($copy['text'] ?? null);
            $out[] = [
                'node_id' => (string) $node['id'],
                'channel' => $channel,
                'template_key' => $key,
                'name' => $entry['name'] ?? null,
                'kind' => (string) ($node['config']['kind'] ?? 'marketing'),
                'subject' => $this->fill($copy['subject'] ?? null, $data, $register),
                'preheader' => $this->fill($copy['preheader'] ?? null, $data, $register),
                'text' => $this->fill(is_string($text) && $text !== '' ? $text : null, $data, $register),
                'buttons' => array_values(array_filter($buttons, fn (string $b) => $b !== '')),
                'in_library' => $entry !== null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $register
     */
    private function fill(mixed $text, array $data, array $register): ?string
    {
        if (! is_string($text) || trim($text) === '') {
            return null;
        }
        try {
            return trim($this->sandbox->render($text, $data, $register, __('el texto')));
        } catch (TemplateRenderException) {
            return $text;
        }
    }
}
