<?php

declare(strict_types=1);

namespace App\Domain\Templates\Contracts;

use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Data\RenderedMessage;

/**
 * Renderiza una plantilla con Liquid en sandbox (sin acceso a archivos ni a PHP,
 * con tiempo máximo) y los datos de RenderContext. Lanza
 * App\Domain\Templates\Exceptions\TemplateRenderException con un mensaje en
 * español si la plantilla no se puede renderizar.
 */
interface TemplateRenderer
{
    public function render(string $templateId, RenderContext $context): RenderedMessage;
}
