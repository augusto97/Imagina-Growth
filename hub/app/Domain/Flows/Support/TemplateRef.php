<?php

declare(strict_types=1);

namespace App\Domain\Flows\Support;

use Illuminate\Support\Facades\DB;

/**
 * Plantillas de los nodos de envío. Un nodo guarda `template_id` (elegida en el
 * constructor) o `template_key` (la `library_key` de la biblioteca de M08, para
 * los flujos listos). Se lee la tabla `templates` directamente: la gestiona M08.
 */
final class TemplateRef
{
    /** Plantilla genérica de los avisos al equipo (library_key de M08). */
    public const TEAM_ALERT = 'team_alert';

    /** ID interno (ULID) de la plantilla, o null si no hay una usable para ese canal. */
    public static function resolve(string $workspaceId, string $channel, mixed $templateId, mixed $templateKey): ?string
    {
        $base = DB::table('templates')->where('workspace_id', $workspaceId)->where('channel', $channel)->where('status', '!=', 'archived');
        if (is_string($templateId) && $templateId !== '') {
            $id = self::decode($templateId);
            $found = (clone $base)->where('id', $id)->value('id');
            if (is_string($found)) {
                return $found;
            }
        }
        if (is_string($templateKey) && $templateKey !== '') {
            $found = (clone $base)->where('library_key', $templateKey)->orderByDesc('updated_at')->value('id');

            return is_string($found) ? $found : null;
        }

        return null;
    }

    /** Acepta el ID público (`tpl_…`) o el ULID. */
    public static function decode(string $id): string
    {
        $pos = strrpos($id, '_');

        return strtoupper($pos === false ? $id : substr($id, $pos + 1));
    }

    /** ID público con el prefijo del modelo de M08 si existe. */
    public static function encode(string $ulid): string
    {
        $class = 'App\\Domain\\Templates\\Models\\Template';
        $prefix = class_exists($class) && defined($class.'::ID_PREFIX') ? (string) constant($class.'::ID_PREFIX') : 'tpl';

        return $prefix.'_'.$ulid;
    }
}
