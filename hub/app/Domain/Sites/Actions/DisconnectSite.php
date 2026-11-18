<?php

declare(strict_types=1);

namespace App\Domain\Sites\Actions;

use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Support\SiteKeys;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/** Desconecta el sitio: invalida sus claves. Los datos ya recibidos se conservan. */
final class DisconnectSite
{
    public function __construct(private RecordAuditLog $audit) {}

    public function handle(Site $site): Site
    {
        $site->forceFill([
            'status' => Site::STATUS_DISCONNECTED,
            'secret' => SiteKeys::secret(),
            'previous_secret' => null,
            'previous_secret_expires_at' => null,
        ])->save();

        $this->audit->handle('site.disconnected', $site, [], $site->workspace_id);

        return $site;
    }
}
