<?php

declare(strict_types=1);

namespace App\Ingest\Http;

use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;

/**
 * Busca el sitio de una petición de ingesta sin pasar por el scope de tenancy
 * (todavía no se sabe el espacio de trabajo). Sin caché: dos consultas por clave primaria.
 */
final class SiteResolver
{
    /** @return array{0: Site, 1: int}|null sitio y ch_id de su espacio de trabajo */
    public function byPublicId(?string $publicId): ?array
    {
        $id = PrefixedId::decode(Site::ID_PREFIX, $publicId);

        return $id === null ? null : $this->withWorkspace(Site::withoutWorkspaceScope()->find($id));
    }

    /** @return array{0: Site, 1: int}|null */
    public function byPublicKey(?string $key): ?array
    {
        if ($key === null || ! preg_match('/^pk_[0-9a-z]{32}$/', $key)) {
            return null;
        }

        return $this->withWorkspace(Site::withoutWorkspaceScope()->where('public_key', $key)->first());
    }

    /** @return array{0: Site, 1: int}|null */
    private function withWorkspace(?Site $site): ?array
    {
        if ($site === null) {
            return null;
        }

        return [$site, (int) Workspace::query()->whereKey($site->workspace_id)->value('ch_id')];
    }
}
