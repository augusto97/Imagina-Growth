<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot;

use App\Domain\Intelligence\Ai\Copilot\Models\AiDraft;
use App\Domain\Intelligence\Ai\Copilot\Support\BrandTone;
use App\Domain\Intelligence\Ai\Copilot\Support\CopilotFailed;
use App\Domain\Intelligence\Ai\Copilot\Support\WhatsAppTemplateRules;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Support\NarrativeWriter;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Redactor (inteligencia.md §6.5): asuntos y preencabezados, cuerpos de plantillas
 * de WhatsApp que cumplen las reglas de Meta y variantes A/B, con el tono de marca.
 * Toda cifra debe estar en lo que dio la persona (la oferta, el precio): si el
 * modelo inventa una, se le pide corregir una vez y si insiste no se entrega.
 * Cada resultado queda en `ai_drafts` (tipo `content`) como historial.
 */
final class ContentWriter
{
    public function __construct(
        private readonly NarrativeWriter $writer,
        private readonly BrandTone $tone,
    ) {}

    /**
     * @param  array{goal: string, offer?: string|null, audience?: string|null}  $brief
     * @return array{draft: AiDraft, variants: list<array{subject: string, preheader: string}>}
     */
    public function subjects(Workspace $workspace, ?string $userId, array $brief, int $count = 5): array
    {
        $count = max(1, min(10, $count));
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['variants'], 'properties' => [
            'variants' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['subject', 'preheader'],
                'properties' => ['subject' => ['type' => 'string'], 'preheader' => ['type' => 'string']]]],
        ]];
        $json = $this->generate($workspace, 'Escribe '.$count.' asuntos de correo (máximo 60 caracteres) con su preencabezado (máximo 90). Distintos entre sí: curiosidad, beneficio, urgencia sin mentir. Puedes usar {{ contacto.nombre }}.', $brief, $schema);
        $variants = [];
        foreach (array_slice((array) ($json['variants'] ?? []), 0, $count) as $v) {
            if (is_array($v) && trim((string) ($v['subject'] ?? '')) !== '') {
                $variants[] = ['subject' => mb_substr(trim((string) $v['subject']), 0, 120), 'preheader' => mb_substr(trim((string) ($v['preheader'] ?? '')), 0, 160)];
            }
        }

        return ['draft' => $this->store($workspace, $userId, 'subjects', $brief, ['variants' => $variants]), 'variants' => $variants];
    }

    /**
     * Cuerpo de una plantilla de WhatsApp para enviar a aprobación de Meta.
     *
     * @param  array{goal: string, offer?: string|null, audience?: string|null}  $brief
     * @return array{draft: AiDraft, body: string, variables: list<array{index: int, meaning: string, example: string}>, category: string, problems: list<string>}
     */
    public function whatsapp(Workspace $workspace, ?string $userId, array $brief, string $category = 'marketing'): array
    {
        $category = in_array($category, ['marketing', 'utility'], true) ? $category : 'marketing';
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['body', 'variables'], 'properties' => [
            'body' => ['type' => 'string'],
            'variables' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['index', 'meaning', 'example'],
                'properties' => ['index' => ['type' => 'integer'], 'meaning' => ['type' => 'string'], 'example' => ['type' => 'string']]]],
        ]];
        $instructions = 'Escribe el cuerpo de una plantilla de WhatsApp de categoría '.($category === 'utility' ? 'utilidad (informativa, sin promoción)' : 'marketing')
            .'. Reglas de Meta: máximo 1.024 caracteres, variables numeradas {{1}}, {{2}}… seguidas, el texto no empieza ni termina con una variable, nunca dos variables pegadas, pocas variables, sin acortadores de enlaces, sin contenido prohibido. Explica cada variable con un ejemplo.';
        $json = $this->generate($workspace, $instructions, $brief, $schema, fn (array $j) => WhatsAppTemplateRules::check((string) ($j['body'] ?? '')));
        $body = trim((string) ($json['body'] ?? ''));
        $variables = [];
        foreach ((array) ($json['variables'] ?? []) as $v) {
            if (is_array($v)) {
                $variables[] = ['index' => (int) ($v['index'] ?? 0), 'meaning' => mb_substr((string) ($v['meaning'] ?? ''), 0, 120), 'example' => mb_substr((string) ($v['example'] ?? ''), 0, 120)];
            }
        }
        $problems = WhatsAppTemplateRules::check($body);
        $result = ['body' => $body, 'variables' => $variables, 'category' => $category, 'problems' => $problems];

        return ['draft' => $this->store($workspace, $userId, 'whatsapp', $brief + ['category' => $category], $result)] + $result;
    }

    /**
     * Variantes A/B de un texto (asunto, cuerpo o WhatsApp).
     *
     * @return array{draft: AiDraft, variants: list<string>}
     */
    public function variants(Workspace $workspace, ?string $userId, string $text, string $kind = 'subject', int $count = 3): array
    {
        $count = max(1, min(5, $count));
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['variants'], 'properties' => [
            'variants' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]];
        $what = match ($kind) {
            'whatsapp' => 'del cuerpo de WhatsApp (conserva las variables {{n}} y las reglas de Meta)',
            'body' => 'del texto del correo',
            default => 'del asunto (máximo 60 caracteres)',
        };
        $json = $this->generate($workspace, "Escribe {$count} variantes {$what} para una prueba A/B. Cambia el enfoque, no solo palabras.", ['goal' => $text],
            $schema, $kind === 'whatsapp' ? fn (array $j) => array_merge(...array_map(fn ($v) => WhatsAppTemplateRules::check((string) $v), (array) ($j['variants'] ?? [])) ?: [[]]) : null);
        $variants = array_values(array_filter(array_map(fn ($v) => trim(mb_substr((string) $v, 0, $kind === 'subject' ? 120 : 1024)), array_slice((array) ($json['variants'] ?? []), 0, $count))));

        return ['draft' => $this->store($workspace, $userId, 'variants', ['text' => $text, 'kind' => $kind], ['variants' => $variants]), 'variants' => $variants];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $schema
     * @param  (callable(array<string, mixed>): list<string>)|null  $rules  reglas extra (WhatsApp)
     * @return array<string, mixed>
     *
     * @throws AiUnavailable
     * @throws CopilotFailed
     */
    private function generate(Workspace $workspace, string $instructions, array $brief, array $schema, ?callable $rules = null): array
    {
        $tone = $this->tone->cached($workspace);
        $system = "Eres el redactor de Imagina Growth para tiendas de Colombia, México y Venezuela. Español neutro, claro y cercano. No inventes cifras, precios, fechas ni códigos de cupón: usa solo los del pedido. No uses datos personales.\n".$instructions;
        $prompt = (string) json_encode(['pedido' => array_filter($brief, fn ($v) => $v !== null && $v !== ''), 'tono_de_marca' => $tone !== null ? array_intersect_key($tone, array_flip(['summary', 'traits', 'do', 'dont'])) : null], JSON_UNESCAPED_UNICODE);
        $request = AiRequest::ask($workspace->id, 'content', $system, $prompt, $schema);
        $facts = ['pedido' => $brief];
        $result = $this->writer->write($request, $facts, fn () => '', self::validatedText(...));
        if (! $result->fromAi() || $result->json === null) {
            $reason = (string) ($result->validation['fallback_reason'] ?? 'numbers');
            if ($reason !== 'numbers') {
                throw new AiUnavailable($reason, __('La IA no está disponible ahora. Escribe el texto a mano.'));
            }
            throw new CopilotFailed(__('La IA propuso cifras que no están en tu pedido dos veces. Revisa el pedido o escribe el texto a mano.'));
        }
        if ($rules !== null && ($problems = $rules($result->json)) !== []) {
            // Una corrección por las reglas de Meta, validando otra vez las cifras.
            $retry = $this->writer->write($request->followUp((string) json_encode($result->json, JSON_UNESCAPED_UNICODE),
                'No cumple las reglas de Meta: '.implode(' ', $problems).' Corrígelo.'), $facts, fn () => '', self::validatedText(...));
            if ($retry->fromAi() && $retry->json !== null) {
                return $retry->json;
            }
        }

        return $result->json;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     */
    private function store(Workspace $workspace, ?string $userId, string $type, array $input, array $output): AiDraft
    {
        return AiDraft::query()->create([
            'workspace_id' => $workspace->id, 'user_id' => $userId, 'kind' => 'content',
            'prompt' => mb_substr((string) ($input['goal'] ?? $input['text'] ?? ''), 0, 2000),
            'proposal' => ['type' => $type, 'input' => $input, 'output' => $output], 'model' => (string) config('igr-ai.features.content.model'),
        ]);
    }

    /** Los textos de la respuesta sin las variables (`{{1}}`, `{{ contacto.nombre }}`), que no son cifras. */
    public static function validatedText(AiResult $result): string
    {
        $out = [];
        $json = $result->json ?? [];
        array_walk_recursive($json, function (mixed $v, int|string $key) use (&$out): void {
            if (is_string($v) && $key !== 'example') {
                $out[] = $v;
            }
        });

        return (string) preg_replace('/\{\{[^}]*\}\}/', ' ', $result->json !== null ? implode("\n", $out) : $result->text);
    }
}
