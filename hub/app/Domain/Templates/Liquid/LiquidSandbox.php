<?php

declare(strict_types=1);

namespace App\Domain\Templates\Liquid;

use App\Domain\Templates\Exceptions\TemplateRenderException;
use Keepsuit\Liquid\Environment;
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Exceptions\FileSystemException;
use Keepsuit\Liquid\Exceptions\LiquidException;
use Keepsuit\Liquid\Exceptions\ResourceLimitException;
use Keepsuit\Liquid\Exceptions\StackLevelException;
use Keepsuit\Liquid\Exceptions\SyntaxException;
use Keepsuit\Liquid\Exceptions\UndefinedFilterException;
use Keepsuit\Liquid\FileSystems\BlankFileSystem;
use Keepsuit\Liquid\Render\ResourceLimits;
use Throwable;

/**
 * Liquid en sandbox para las plantillas (hub/CLAUDE.md §8, modulos.md M08):
 *
 * - Sin sistema de archivos (`render` falla), sin PHP: solo los tags y filtros de
 *   Liquid más TemplateFilters.
 * - Límites de recursos: tamaño de salida, «puntaje» de render y de asignación
 *   (un `for` sobre un rango enorme se corta), que hacen de tiempo máximo.
 * - Filtros desconocidos son error; variables desconocidas quedan vacías (usa
 *   `| default: "…"`).
 *
 * Los errores salen como TemplateRenderException con un mensaje en español.
 */
final class LiquidSandbox
{
    public const MAX_OUTPUT = 600_000;

    private ?Environment $environment = null;

    public function environment(): Environment
    {
        return $this->environment ??= EnvironmentFactory::new()
            ->setFilesystem(new BlankFileSystem)
            ->setStrictVariables(false)
            ->setStrictFilters(true)
            ->setRethrowErrors(true)
            ->setLazyParsing(false)
            ->setResourceLimits(self::limits())
            ->registerFilters(TemplateFilters::class)
            ->build();
    }

    public static function limits(): ResourceLimits
    {
        return new ResourceLimits(
            renderLengthLimit: self::MAX_OUTPUT,
            renderScoreLimit: 200_000,
            assignScoreLimit: 20_000,
            cumulativeRenderScoreLimit: 400_000,
            cumulativeAssignScoreLimit: 40_000,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $register  moneda, locale y zona horaria para los filtros
     */
    public function render(string $source, array $data, array $register = [], string $part = 'plantilla'): string
    {
        if ($source === '') {
            return '';
        }
        $env = $this->environment();
        try {
            $template = $env->parseString($source);
        } catch (SyntaxException $e) {
            throw new TemplateRenderException(__('Error de sintaxis en :part: :msg', ['part' => $part, 'msg' => self::clean($e->getMessage())]), 0, $e);
        } catch (Throwable $e) {
            throw new TemplateRenderException(__('No se pudo leer :part: :msg', ['part' => $part, 'msg' => self::clean($e->getMessage())]), 0, $e);
        }

        try {
            $context = $env->newRenderContext(data: $data, registers: ['igr' => $register], resourceLimits: self::limits());

            return $template->render($context);
        } catch (ResourceLimitException|StackLevelException $e) {
            throw new TemplateRenderException(__('La :part es demasiado pesada: superó el límite de tiempo o de tamaño al renderizarse.', ['part' => $part]), 0, $e);
        } catch (FileSystemException $e) {
            throw new TemplateRenderException(__('Las plantillas no pueden incluir otros archivos (`render` o `include`).'), 0, $e);
        } catch (UndefinedFilterException $e) {
            throw new TemplateRenderException(__('Filtro desconocido en :part: :msg', ['part' => $part, 'msg' => self::clean($e->getMessage())]), 0, $e);
        } catch (LiquidException $e) {
            throw new TemplateRenderException(__('Error al renderizar :part: :msg', ['part' => $part, 'msg' => self::clean($e->getMessage())]), 0, $e);
        } catch (TemplateRenderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TemplateRenderException(__('Error al renderizar :part: :msg', ['part' => $part, 'msg' => self::clean($e->getMessage())]), 0, $e);
        }
    }

    /** Valida solo la sintaxis (al guardar). Devuelve el mensaje de error o null. */
    public function syntaxError(string $source): ?string
    {
        if ($source === '') {
            return null;
        }
        try {
            $this->environment()->parseString($source);

            return null;
        } catch (Throwable $e) {
            return self::clean($e->getMessage());
        }
    }

    private static function clean(string $message): string
    {
        return mb_substr(trim(preg_replace('/^Liquid (syntax )?error:\s*/i', '', $message) ?? $message), 0, 300);
    }
}
