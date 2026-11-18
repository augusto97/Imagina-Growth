<?php

declare(strict_types=1);

namespace App\Domain\Segments\Support;

use App\Domain\Segments\Compiler\SegmentCompiler;
use App\Domain\Segments\Models\Segment;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;

/**
 * Qué segmentos dinámicos dependen de qué eventos, para decidir si un evento
 * entrante amerita recalcular al contacto en tiempo real (hub/CLAUDE.md §6).
 * En caché por espacio de trabajo; se invalida al guardar un segmento.
 */
final class DependencyIndex
{
    /** Eventos que cambian atributos y agregados del contacto. */
    private const PROFILE_EVENTS = ['customer.registered', 'customer.updated', 'form.submitted', 'survey.answered', 'identify'];

    public static function key(string $workspaceId): string
    {
        return RedisKey::for($workspaceId, 'segments:deps');
    }

    public static function forget(string $workspaceId): void
    {
        Cache::forget(self::key($workspaceId));
    }

    /**
     * Debe correr con el espacio de trabajo activo.
     *
     * @return list<array{id: string, events: list<string>, kinds: list<string>}>
     */
    public static function load(string $workspaceId): array
    {
        /** @var list<array{id: string, events: list<string>, kinds: list<string>}> */
        return Cache::remember(self::key($workspaceId), 300, fn () => Segment::query()
            ->where('kind', Segment::KIND_DYNAMIC)
            ->whereNotNull('definition')
            ->whereNotNull('last_computed_at')
            ->get(['id', 'definition'])
            ->map(fn (Segment $s) => ['id' => $s->id] + SegmentCompiler::dependencies((array) $s->definition))
            ->values()
            ->all());
    }

    /**
     * ¿Algún segmento depende de este evento?
     *
     * @param  list<array{id: string, events: list<string>, kinds: list<string>}>  $index
     */
    public static function isRelevant(array $index, string $event): bool
    {
        if (str_starts_with($event, 'segment.')) {
            return false; // evita cascadas: lo de segmentos que dependen de segmentos lo resuelve el recálculo nocturno
        }
        foreach ($index as $entry) {
            if (in_array($event, $entry['events'], true)) {
                return true;
            }
            $kinds = $entry['kinds'];
            if (str_starts_with($event, 'order.') && array_intersect($kinds, ['purchase', 'attribute', 'cart']) !== []) {
                return true;
            }
            if ((str_starts_with($event, 'cart.') || $event === 'checkout.started') && in_array('cart', $kinds, true)) {
                return true;
            }
            if (in_array($event, self::PROFILE_EVENTS, true) && in_array('attribute', $kinds, true)) {
                return true;
            }
            if (str_starts_with($event, 'consent.') && in_array('consent', $kinds, true)) {
                return true;
            }
            if ((str_starts_with($event, 'message.') || $event === 'whatsapp.inbound') && in_array('messaging', $kinds, true)) {
                return true;
            }
            if (str_starts_with($event, 'lead.') && in_array('lead', $kinds, true)) {
                return true;
            }
            if (self::isLoyaltyEvent($event) && in_array('loyalty', $kinds, true)) {
                return true;
            }
        }

        return false;
    }

    /** Eventos que mueven puntos, saldo, nivel o referidos (Fase 3). */
    private static function isLoyaltyEvent(string $event): bool
    {
        return str_starts_with($event, 'points.') || str_starts_with($event, 'wallet.') || str_starts_with($event, 'referral.') || $event === 'tier.changed';
    }
}
