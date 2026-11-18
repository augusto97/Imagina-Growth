<?php

declare(strict_types=1);

namespace App\Domain\Sites\Actions;

use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Support\SiteKeys;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * Rota el secreto: envía el nuevo al sitio firmado con el actual y, si el
 * sitio lo acepta, guarda el anterior durante 24 h (api-connector.md §2 y §6).
 */
final class RotateSiteSecret
{
    public function __construct(private SendSiteCommand $command, private RecordAuditLog $audit) {}

    public function handle(Site $site): Site
    {
        $old = $site->secret;
        $new = SiteKeys::secret();

        $this->command->handle($site, 'secret.rotate', ['secret' => $new], signWith: $old);

        $site->forceFill([
            'secret' => $new,
            'previous_secret' => $old,
            'previous_secret_expires_at' => now()->addHours((int) config('igr.signature.previous_secret_grace_hours')),
            'secret_rotated_at' => now(),
        ])->save();

        $this->audit->handle('site.secret_rotated', $site, [], $site->workspace_id);

        return $site;
    }
}
