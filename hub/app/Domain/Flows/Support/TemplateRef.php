<?php

declare(strict_types=1);

namespace App\Domain\Flows\Support;

use Illuminate\Database\Query\Builder;
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
            $query = (clone $base)->where('library_key', $templateKey);
            if ($channel === 'whatsapp') {
                self::usableWhatsapp($query);
            }
            $found = $query->orderByDesc('updated_at')->value('id');

            return is_string($found) ? $found : null;
        }

        return null;
    }

    /**
     * Una plantilla de WhatsApp de la biblioteca en borrador (instalada desde la galería, todavía sin la aprobada de
     * Meta) no la usan los flujos por su clave: lista, o enlazada con una aprobada.
     *
     * @param  Builder  $query  sobre `templates`
     */
    public static function usableWhatsapp(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('templates.status', 'ready')->orWhereExists(
            fn (Builder $w) => $w->from('wa_templates')->whereColumn('wa_templates.id', 'templates.wa_template_id')->where('wa_templates.status', 'APPROVED')
        ));
    }

    /**
     * Claves de la biblioteca que el espacio de trabajo ya tiene listas para los flujos.
     *
     * @return array<string, true>
     */
    public static function usableKeys(string $workspaceId): array
    {
        $rows = DB::table('templates')->where('workspace_id', $workspaceId)->whereNotNull('library_key')->where('status', '!=', 'archived')
            ->where(fn (Builder $q) => $q->where('channel', '!=', 'whatsapp')->orWhere(fn (Builder $w) => self::usableWhatsapp($w->where('channel', 'whatsapp'))))
            ->distinct()->pluck('library_key');

        return array_fill_keys(array_map('strval', $rows->all()), true);
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
