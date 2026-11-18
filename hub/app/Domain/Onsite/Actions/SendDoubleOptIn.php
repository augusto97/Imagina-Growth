<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Actions;

use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Support\OptInToken;
use App\Domain\Sites\Models\Site;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Correo de doble opt-in (M12, cumplimiento.md §3.1): sale por MessageDispatcher como
 * mensaje transaccional (responde a lo que la persona pidió, no es comercial) con la
 * plantilla de correo elegida en el widget o, si no hay, un texto del sistema. El
 * enlace llega a la plantilla como `{{ enlaces.confirmar }}`. Uno por contacto,
 * widget y día. Debe correr dentro del contexto del espacio de trabajo.
 */
final class SendDoubleOptIn
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function handle(Workspace $workspace, Site $site, OnsiteWidget $widget, string $contactId): DispatchResult
    {
        $url = OptInToken::url((string) $site->getAttribute('public_key'), $workspace->id, $contactId, $widget->id);
        $templatePublicId = $widget->consent_config['template_id'] ?? null;
        $template = is_string($templatePublicId) ? Template::findByPublicId($templatePublicId) : null;
        $brand = (string) ($workspace->getAttribute('legal_name') ?: $workspace->name);

        $context = ['enlaces' => ['confirmar' => $url]];
        if ($template === null) {
            $subject = __('Confirma tu suscripción a :brand', ['brand' => $brand]);
            $text = __("Hola:\n\nConfirma que quieres recibir ofertas y novedades de :brand por correo:\n:url\n\nSi no fuiste tú, ignora este mensaje y no te escribiremos.", ['brand' => $brand, 'url' => $url]);
            $html = '<div style="font-family:Inter,Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#111827">'
                .'<h1 style="font-size:20px;margin:0 0 12px">'.e(__('Confirma tu suscripción')).'</h1>'
                .'<p style="font-size:15px;line-height:1.5">'.e(__('Confirma que quieres recibir ofertas y novedades de :brand por correo.', ['brand' => $brand])).'</p>'
                .'<p style="margin:24px 0"><a href="'.e($url).'" style="background:#4F46E5;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:600">'.e(__('Sí, quiero suscribirme')).'</a></p>'
                .'<p style="font-size:13px;color:#6B7280">'.e(__('Si no fuiste tú, ignora este mensaje y no te escribiremos.')).'</p></div>';
            $context += ['subject' => $subject, 'text' => $text, 'html' => $html];
        }

        return $this->dispatcher->dispatch(new OutboundMessage(
            workspaceId: $workspace->id,
            contactId: $contactId,
            channel: Channel::Email,
            kind: MessageKind::Transactional,
            sourceType: 'system',
            sourceId: $widget->publicId(),
            templateId: $template?->id,
            context: $context,
            idempotencyKey: 'optin:'.$widget->id.':'.$contactId.':'.now()->format('Ymd'),
        ));
    }
}
