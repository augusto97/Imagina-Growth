<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Data;

/**
 * Pedido al modelo. `system` va primero y se cachea (es estable por función);
 * lo variable (hechos, pregunta, conversación) va en `messages`.
 */
final class AiRequest
{
    /**
     * @param  string  $feature  clave de `igr-ai.features` (weekly_analyst, ask_data, copilot, content,
     *                           sales_agent, inbound_classifier, review_moderator, profile_summary, review_summary)
     * @param  list<array{role: 'user'|'assistant', content: string|list<array<string, mixed>>}>  $messages
     * @param  array<string, mixed>|null  $outputSchema  JSON Schema (con additionalProperties false y required)
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $feature,
        public readonly string $system,
        public readonly array $messages,
        public readonly ?array $outputSchema = null,
        public readonly ?int $maxTokens = null,
        public readonly ?string $effort = null,
    ) {}

    /**
     * Pedido de un solo mensaje del usuario.
     *
     * @param  array<string, mixed>|null  $outputSchema
     */
    public static function ask(string $workspaceId, string $feature, string $system, string $prompt, ?array $outputSchema = null): self
    {
        return new self($workspaceId, $feature, $system, [['role' => 'user', 'content' => $prompt]], $outputSchema);
    }

    /** El mismo pedido con un mensaje más del usuario (p. ej. la corrección tras validar las cifras). */
    public function followUp(string $assistantText, string $userText): self
    {
        return new self($this->workspaceId, $this->feature, $this->system, [
            ...$this->messages,
            ['role' => 'assistant', 'content' => $assistantText],
            ['role' => 'user', 'content' => $userText],
        ], $this->outputSchema, $this->maxTokens, $this->effort);
    }
}
