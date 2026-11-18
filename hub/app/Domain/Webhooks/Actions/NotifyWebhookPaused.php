<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Actions;

use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Webhooks\Models\WebhookSubscription;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Throwable;

/** Avisa por correo a los dueños que un webhook se pausó por fallos seguidos. */
final class NotifyWebhookPaused
{
    public function __construct(private readonly MessageDispatcher $dispatcher, private readonly CurrentWorkspace $current) {}

    public function handle(WebhookSubscription $sub, string $lastError): void
    {
        $workspace = Workspace::query()->find($sub->workspace_id);
        if ($workspace === null) {
            return;
        }
        $this->current->run($workspace, function () use ($workspace, $sub, $lastError): void {
            TemplateLibrary::install($workspace, TemplateRef::TEAM_ALERT);
            $templateId = TemplateRef::resolve($workspace->id, 'email', null, TemplateRef::TEAM_ALERT);
            if ($templateId === null) {
                return;
            }
            $host = (string) parse_url($sub->url, PHP_URL_HOST);
            $title = __('Pausamos un webhook que no responde');
            $summary = __('El webhook «:name» a :host falló :n veces seguidas y quedó en pausa. Revisa el servidor que lo recibe y reactívalo desde API e integraciones.', [
                'name' => $sub->name ?? $host, 'host' => $host, 'n' => $sub->consecutive_failures,
            ]);
            $url = rtrim((string) config('app.url'), '/').'/w/'.$workspace->publicId().'/integraciones';
            $owners = $workspace->users()->wherePivot('role', WorkspaceRole::Owner->value)->pluck('email')->map(fn ($e) => (string) $e)->all();
            foreach (array_slice($owners, 0, 10) as $i => $to) {
                try {
                    $this->dispatcher->dispatch(new OutboundMessage(
                        workspaceId: $workspace->id,
                        contactId: null,
                        channel: Channel::Email,
                        kind: MessageKind::Utility,
                        sourceType: 'system',
                        sourceId: $sub->id,
                        templateId: $templateId,
                        context: ['aviso' => ['titulo' => $title, 'resumen' => $summary, 'detalle' => __('Último error: :error', ['error' => $lastError]), 'texto' => $summary, 'url' => $url]],
                        idempotencyKey: 'webhook_paused:'.$sub->id.':'.now()->format('YmdHi').':'.$i,
                        to: $to,
                    ));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }
}
