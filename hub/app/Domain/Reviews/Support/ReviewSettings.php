<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Support;

use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes de reseñas de un espacio de trabajo (`workspaces.settings.reviews`).
 *
 * - `request`: solicitud automática. `trigger` (order.completed o order.paid),
 *   `days` por defecto y `category_days` por categoría de la tienda (gana la
 *   mayor del pedido), `channels` en orden de preferencia (WhatsApp → correo),
 *   las plantillas, `skip_reviewed` y la clase legal del mensaje (`kind`:
 *   marketing por defecto; reclasificarlo exige `reclassify_reason`, que queda
 *   en la auditoría, cumplimiento.md §4.1).
 * - `moderation.mode`: `manual` (todas pasan por la cola) o `auto` (se publican
 *   las que no tienen banderas, con cualquier calificación).
 * - `google_review_url`: enlace directo para pedir la reseña en Google Business
 *   Profile. Se ofrece a todos por igual, sin filtrar por la calificación.
 * - `publish_to_store`: publicar en WooCommerce las aprobadas (review.publish).
 *
 * @phpstan-type Settings array{request: array{enabled: bool, trigger: string, days: int, category_days: list<array{category_id: string, days: int}>, channels: list<string>, email_template_id: string|null, whatsapp_template_id: string|null, sms_template_id: string|null, skip_reviewed: bool, kind: string, reclassify_reason: string|null, link_days: int}, moderation: array{mode: string}, google_review_url: string|null, publish_to_store: bool, reply_author: string|null}
 */
final class ReviewSettings
{
    public const CHANNELS = ['whatsapp', 'email', 'sms'];

    /** @return Settings */
    public static function defaults(): array
    {
        return [
            'request' => [
                'enabled' => false,
                'trigger' => 'completed',
                'days' => 7,
                'category_days' => [],
                'channels' => ['whatsapp', 'email'],
                'email_template_id' => null,
                'whatsapp_template_id' => null,
                'sms_template_id' => null,
                'skip_reviewed' => true,
                'kind' => 'marketing',
                'reclassify_reason' => null,
                'link_days' => 60,
            ],
            'moderation' => ['mode' => 'manual'],
            'google_review_url' => null,
            'publish_to_store' => true,
            'reply_author' => null,
        ];
    }

    /** @return Settings */
    public static function for(Workspace $workspace): array
    {
        $stored = $workspace->setting('reviews', []);
        $stored = is_array($stored) ? $stored : [];
        $d = self::defaults();
        $r = is_array($stored['request'] ?? null) ? $stored['request'] : [];

        $channels = array_values(array_filter(array_map('strval', (array) ($r['channels'] ?? $d['request']['channels'])), fn ($c) => in_array($c, self::CHANNELS, true)));
        $categoryDays = [];
        foreach ((array) ($r['category_days'] ?? []) as $row) {
            if (is_array($row) && is_scalar($row['category_id'] ?? null) && (string) $row['category_id'] !== '') {
                $categoryDays[] = ['category_id' => (string) $row['category_id'], 'days' => self::days($row['days'] ?? null, $d['request']['days'])];
            }
        }
        $kind = in_array($r['kind'] ?? null, ['marketing', 'utility'], true) ? (string) $r['kind'] : 'marketing';

        return [
            'request' => [
                'enabled' => (bool) ($r['enabled'] ?? $d['request']['enabled']),
                'trigger' => in_array($r['trigger'] ?? null, ['completed', 'paid'], true) ? (string) $r['trigger'] : 'completed',
                'days' => self::days($r['days'] ?? null, $d['request']['days']),
                'category_days' => $categoryDays,
                'channels' => $channels === [] ? $d['request']['channels'] : array_values(array_unique($channels)),
                'email_template_id' => self::str($r['email_template_id'] ?? null),
                'whatsapp_template_id' => self::str($r['whatsapp_template_id'] ?? null),
                'sms_template_id' => self::str($r['sms_template_id'] ?? null),
                'skip_reviewed' => (bool) ($r['skip_reviewed'] ?? true),
                'kind' => $kind,
                'reclassify_reason' => $kind === 'marketing' ? null : self::str($r['reclassify_reason'] ?? null),
                'link_days' => max(7, min(180, (int) ($r['link_days'] ?? $d['request']['link_days']))),
            ],
            'moderation' => ['mode' => ($stored['moderation']['mode'] ?? null) === 'auto' ? 'auto' : 'manual'],
            'google_review_url' => self::str($stored['google_review_url'] ?? null),
            'publish_to_store' => (bool) ($stored['publish_to_store'] ?? true),
            'reply_author' => self::str($stored['reply_author'] ?? null),
        ];
    }

    /**
     * Guarda los ajustes con la fila del espacio de trabajo bloqueada (otros
     * módulos escriben en el mismo JSON de `settings`).
     *
     * @param  array<string, mixed>  $reviews
     */
    public static function save(Workspace $workspace, array $reviews): void
    {
        DB::transaction(function () use ($workspace, $reviews): void {
            /** @var Workspace $locked */
            $locked = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            $settings = is_array($locked->settings) ? $locked->settings : [];
            $settings['reviews'] = $reviews;
            $locked->forceFill(['settings' => $settings])->save();
            $workspace->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Días de espera para un pedido: el mayor de sus categorías configuradas, o el
     * valor por defecto.
     *
     * @param  list<string>  $categoryIds
     * @param  array{days: int, category_days: list<array{category_id: string, days: int}>}  $request
     */
    public static function daysFor(array $categoryIds, array $request): int
    {
        $days = null;
        foreach ($request['category_days'] as $row) {
            if (in_array($row['category_id'], $categoryIds, true)) {
                $days = max($days ?? 0, $row['days']);
            }
        }

        return $days ?? $request['days'];
    }

    private static function days(mixed $value, int $default): int
    {
        return is_numeric($value) ? max(0, min(120, (int) $value)) : $default;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
