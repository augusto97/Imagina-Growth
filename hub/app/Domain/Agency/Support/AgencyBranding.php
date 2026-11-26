<?php

declare(strict_types=1);

namespace App\Domain\Agency\Support;

use App\Domain\Agency\Models\AccountBranding as BrandingModel;
use App\Domain\Agency\Models\CustomDomain;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Data\BrandingData;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Support\DefaultBranding;
use Illuminate\Support\Facades\Storage;

/**
 * La marca de la cuenta del espacio de trabajo (M22): con `account_branding` la
 * de la agencia (o la del cliente directo que la configuró); si no, Imagina
 * Growth. El remitente propio solo se usa si hay un dominio de correo verificado
 * en algún canal del espacio de trabajo; si no, se queda el de la plataforma.
 */
final class AgencyBranding implements Branding
{
    /** @var array<string, BrandingData> */
    private array $memo = [];

    /**
     * La marca y el dominio del panel son de la cuenta: los espacios de una agencia los comparten (`/api/me` pedía las
     * dos consultas por cada cliente). El servicio vive lo que dura la petición o el trabajo (`scoped`).
     *
     * @var array<string, array{0: BrandingModel|null, 1: mixed}>
     */
    private array $accounts = [];

    public function for(Workspace $workspace): BrandingData
    {
        return $this->memo[$workspace->id] ??= $this->build($workspace);
    }

    public static function forAccount(string $accountId): ?BrandingModel
    {
        return BrandingModel::query()->find($accountId);
    }

    private function build(Workspace $workspace): BrandingData
    {
        $default = (new DefaultBranding)->for($workspace);
        $accountId = (string) $workspace->account_id;
        [$b, $panel] = $this->accounts[$accountId] ??= [
            self::forAccount($accountId),
            CustomDomain::query()->where('account_id', $accountId)->where('purpose', 'panel')->where('status', 'verified')->orderBy('created_at')->value('host'),
        ];
        if ($b === null && ! is_string($panel)) {
            return $default;
        }
        $sender = is_string($b?->sender_email) && $b->sender_email !== '' && SenderDomain::verifiedFor($workspace->id, $b->sender_email);

        return new BrandingData(
            name: (string) ($b?->brand_name ?: $default->name),
            logoUrl: $b !== null ? self::logoUrl($b) : $default->logoUrl,
            primaryColor: is_string($b?->primary_color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $b->primary_color) === 1 ? strtoupper($b->primary_color) : $default->primaryColor,
            senderName: (string) ($b?->sender_name ?: ($b?->brand_name ?: $default->senderName)),
            senderEmail: $sender && $b !== null ? (string) $b->sender_email : $default->senderEmail,
            supportEmail: $b?->support_email ?: $default->supportEmail,
            panelUrl: is_string($panel) ? 'https://'.$panel : $default->panelUrl,
            whiteLabel: (bool) $b?->white_label,
        );
    }

    public static function logoUrl(BrandingModel $b): ?string
    {
        return is_string($b->logo_path) && $b->logo_path !== '' ? Storage::disk('public')->url($b->logo_path) : null;
    }
}
