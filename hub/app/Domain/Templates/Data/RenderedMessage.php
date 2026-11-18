<?php

declare(strict_types=1);

namespace App\Domain\Templates\Data;

/**
 * Plantilla ya renderizada. Correo: asunto, preheader, HTML y texto. WhatsApp:
 * nombre e idioma de la plantilla aprobada y los parámetros por componente
 * (formato de la Cloud API). SMS: solo `text`.
 */
final class RenderedMessage
{
    /**
     * @param  list<array{type: string, sub_type?: string, index?: int, parameters: list<array<string, mixed>>}>  $waComponents
     */
    public function __construct(
        public readonly ?string $subject = null,
        public readonly ?string $preheader = null,
        public readonly ?string $html = null,
        public readonly ?string $text = null,
        public readonly ?string $waTemplateName = null,
        public readonly ?string $waLanguage = null,
        public readonly array $waComponents = [],
    ) {}
}
