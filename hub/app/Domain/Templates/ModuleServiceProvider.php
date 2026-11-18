<?php

declare(strict_types=1);

namespace App\Domain\Templates;

use App\Domain\Templates\Contracts\TemplateRenderer;
use App\Domain\Templates\Liquid\LiquidSandbox;
use App\Domain\Templates\Rendering\LiquidTemplateRenderer;
use Illuminate\Support\ServiceProvider;

/** Plantillas y contenido (modulos.md M08). */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LiquidSandbox::class);
        $this->app->bind(TemplateRenderer::class, LiquidTemplateRenderer::class);
    }
}
