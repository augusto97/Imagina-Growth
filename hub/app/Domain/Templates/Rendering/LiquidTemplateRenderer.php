<?php

declare(strict_types=1);

namespace App\Domain\Templates\Rendering;

use App\Domain\Templates\Contracts\TemplateRenderer;
use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Data\RenderedMessage;
use App\Domain\Templates\Exceptions\TemplateRenderException;
use App\Domain\Templates\Liquid\LiquidSandbox;
use App\Domain\Templates\Models\Template;
use App\Support\Ids\PrefixedId;
use Illuminate\Support\Facades\DB;

/**
 * TemplateRenderer con Liquid en sandbox (modulos.md M08, hub/CLAUDE.md §8).
 *
 * - Correo: asunto y preheader (una línea), HTML (`body_html`, compilado del MJML
 *   en el navegador) y texto (`body_text` o derivado del HTML).
 * - SMS: `body_text`.
 * - WhatsApp: nombre e idioma de la plantilla aprobada y sus parámetros.
 */
final class LiquidTemplateRenderer implements TemplateRenderer
{
    public function __construct(
        private readonly LiquidSandbox $sandbox,
        private readonly TemplateVariables $variables,
    ) {}

    public function render(string $templateId, RenderContext $context): RenderedMessage
    {
        $id = PrefixedId::decode(Template::ID_PREFIX, $templateId) ?? $templateId;
        $template = Template::withoutWorkspaceScope()->where('workspace_id', $context->workspace->id)->find($id);
        if ($template === null) {
            throw new TemplateRenderException(__('La plantilla no existe en este espacio de trabajo.'));
        }

        return $this->renderTemplate($template, $context);
    }

    public function renderTemplate(Template $template, RenderContext $context): RenderedMessage
    {
        return match ($template->channel) {
            'email' => $this->email($template, $context),
            'sms' => new RenderedMessage(text: $this->plain((string) $template->body_text, $context, __('el SMS'))),
            'whatsapp' => $this->whatsapp($template, $context),
            default => throw new TemplateRenderException(__('Canal de plantilla desconocido.')),
        };
    }

    private function email(Template $template, RenderContext $context): RenderedMessage
    {
        $html = (string) $template->body_html;
        if (trim($html) === '') {
            throw new TemplateRenderException(__('La plantilla de correo no tiene HTML: ábrela en el editor y guárdala.'));
        }
        $register = $this->variables->register($context->workspace);
        $plain = $this->variables->build($context, false);
        $renderedHtml = $this->sandbox->render($html, $this->variables->build($context, true), $register, __('el cuerpo del correo'));
        $text = trim((string) $template->body_text) !== ''
            ? trim($this->sandbox->render((string) $template->body_text, $plain, $register, __('el texto del correo')))
            : HtmlToText::convert($renderedHtml);

        $preheader = self::oneLine($this->sandbox->render((string) $template->preheader, $plain, $register, __('el preheader')));

        return new RenderedMessage(
            subject: self::oneLine($this->sandbox->render((string) $template->subject, $plain, $register, __('el asunto'))),
            preheader: $preheader,
            html: self::withPreheader($renderedHtml, $preheader),
            text: $text,
        );
    }

    private function whatsapp(Template $template, RenderContext $context): RenderedMessage
    {
        $wa = $template->wa_template_id !== null
            ? DB::table('wa_templates')->where('workspace_id', $context->workspace->id)->where('id', $template->wa_template_id)->first()
            : null;
        if ($wa === null) {
            throw new TemplateRenderException(__('Elige una plantilla aprobada de WhatsApp.'));
        }
        if (strtoupper((string) $wa->status) !== 'APPROVED') {
            throw new TemplateRenderException(__('La plantilla de WhatsApp «:name» no está aprobada por Meta.', ['name' => $wa->name]));
        }
        $components = json_decode((string) $wa->components, true);
        $data = $this->variables->build($context, false);
        $register = $this->variables->register($context->workspace);

        return new RenderedMessage(
            waTemplateName: (string) $wa->name,
            waLanguage: (string) $wa->language,
            waComponents: WhatsAppComponents::build(
                is_array($components) ? array_values($components) : [],
                $template->variables ?? [],
                fn (string $source, string $part): string => $this->sandbox->render($source, $data, $register, $part),
            ),
        );
    }

    private function plain(string $source, RenderContext $context, string $part): string
    {
        return trim($this->sandbox->render($source, $this->variables->build($context, false), $this->variables->register($context->workspace), $part));
    }

    /**
     * El preheader va como texto oculto al inicio del cuerpo: es lo que muestra la
     * bandeja junto al asunto. El relleno evita que se cuele el texto del correo.
     */
    public static function withPreheader(string $html, string $preheader): string
    {
        if ($preheader === '' || str_contains($html, 'data-igr-preheader')) {
            return $html;
        }
        $div = '<div data-igr-preheader style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all">'
            .htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8').str_repeat('&#847;&zwnj;&nbsp;', 40).'</div>';
        $out = preg_replace('/(<body\b[^>]*>)/i', '$1'.$div, $html, 1, $count);

        return $count === 1 && is_string($out) ? $out : $div.$html;
    }

    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
