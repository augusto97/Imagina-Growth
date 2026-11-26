<?php

declare(strict_types=1);

namespace App\Domain\Templates\Library;

use App\Domain\Messaging\Channels\Drivers\MetaCloudDriver;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Instalar con un clic una plantilla de la biblioteca o de la galería (M08). Idempotente: la misma clave devuelve la
 * plantilla que ya tiene el espacio de trabajo (salvo que se pida otra copia).
 *
 * Las de WhatsApp quedan en borrador con el texto y el mapeo, y además, si el negocio tiene un número oficial activo
 * (Meta Cloud), se envían a revisión de Meta con su nombre `igr_<clave>` y su categoría, y la plantilla queda enlazada
 * con la de Meta (`wa_template_id`). Si en ese número ya existe una con ese nombre e idioma, se enlaza esa en lugar de
 * enviar otra. Los flujos la usan cuando Meta la aprueba (`TemplateRef::usableWhatsapp`). Un rechazo de Meta no borra
 * la plantilla: vuelve con el motivo para corregirla en el editor.
 */
final class InstallLibraryTemplate
{
    public function __construct(private readonly MetaCloudDriver $meta, private readonly RecordAuditLog $audit, private readonly CurrentWorkspace $current) {}

    /**
     * @return array{template: Template, created: bool, whatsapp: array{status: string, message?: string, wa_template_id?: string, wa_status?: string}|null}
     */
    public function handle(Workspace $workspace, string $key, ?string $industry = null, ?string $userId = null, bool $copy = false, bool $submit = true, ?string $account = null): array
    {
        $entry = TemplateLibrary::entry($key) ?? throw new \InvalidArgumentException(__('La plantilla «:key» no existe en la biblioteca.', ['key' => $key]));
        $template = TemplateLibrary::install($workspace, $key, $industry, $userId, $copy);
        $created = $template->wasRecentlyCreated;
        if ($entry['channel'] !== 'whatsapp') {
            return ['template' => $template, 'created' => $created, 'whatsapp' => null];
        }

        return $this->current->run($workspace, fn () => ['template' => $template, 'created' => $created, 'whatsapp' => $this->whatsapp($workspace, $entry, $template, $submit, $account)]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{status: string, message?: string, wa_template_id?: string, wa_status?: string}
     */
    private function whatsapp(Workspace $workspace, array $entry, Template $template, bool $submit, ?string $accountId): array
    {
        if ($template->wa_template_id !== null) {
            $linked = WaTemplate::query()->find($template->wa_template_id);
            if ($linked !== null) {
                return ['status' => 'linked', 'wa_template_id' => $linked->publicId(), 'wa_status' => $linked->status];
            }
        }
        $account = $accountId !== null ? ChannelAccount::findByPublicId($accountId) : null;
        if ($accountId !== null && ($account === null || $account->provider !== 'meta_cloud' || ! $account->isActive())) {
            throw ValidationException::withMessages(['account' => __('Elige un número de WhatsApp oficial activo.')]);
        }
        $account ??= ChannelAccount::query()->where('channel', 'whatsapp')->where('provider', 'meta_cloud')->where('status', 'active')
            ->orderByDesc('is_default')->orderBy('created_at')->first();
        if ($account === null) {
            return ['status' => 'not_connected', 'message' => __('Conecta un número de WhatsApp oficial para enviarla a revisión de Meta.')];
        }
        $compiled = WhatsAppLibrary::compile($entry, (string) $template->body_text);
        $language = WhatsAppLibrary::language($workspace->country);
        $wa = WaTemplate::query()->where('channel_account_id', $account->id)->where('name', $compiled['name'])->where('language', $language)->first();
        if ($wa === null) {
            if (! $submit) {
                return ['status' => 'ready_to_submit'];
            }
            $wa = new WaTemplate([
                'workspace_id' => $workspace->id, 'channel_account_id' => $account->id, 'name' => $compiled['name'], 'language' => $language,
                'category' => $compiled['category'], 'status' => 'PENDING', 'components' => WhatsAppLibrary::components($compiled),
            ]);
            try {
                [$metaId, $status] = $this->meta->submitTemplate($account, $wa);
            } catch (RuntimeException $e) {
                return ['status' => 'failed', 'message' => __('Meta no aceptó la plantilla: :msg', ['msg' => mb_substr($e->getMessage(), 0, 300)])];
            }
            $wa->fill(['meta_template_id' => $metaId, 'status' => $status, 'synced_at' => now()])->save();
            $this->audit->handle('wa_template.submitted', $wa, ['name' => $wa->name, 'category' => $wa->category, 'source' => 'library'], $workspace->id);
        }
        $template->forceFill(['wa_template_id' => $wa->id, 'variables' => $compiled['variables']])->save();

        return ['status' => $wa->wasRecentlyCreated ? 'submitted' : 'linked', 'wa_template_id' => $wa->publicId(), 'wa_status' => $wa->status];
    }
}
