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
    private const PROFILE_EVENTS = ['customer.registered', 'customer.updated', 'form.submitted', 'survey.answered', 'identify', 'checkout.contact_captured'];

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
     * El índice si ya está en caché (sin tocar la base): así el listener no carga el espacio de
     * trabajo con cada evento.
     *
     * @return list<array{id: string, events: list<string>, kinds: list<string>}>|null
     */
    public static function cached(string $workspaceId): ?array
    {
        $index = Cache::get(self::key($workspaceId));

        /** @var list<array{id: string, events: list<string>, kinds: list<string>}>|null */
        return is_array($index) ? $index : null;
    }

    /**
     * ¿Algún segmento depende de este evento?
     *
     * @param  list<array{id: string, events: list<string>, kinds: list<string>}>  $index
     */
    public static function isRelevant(array $index, string $event): bool
    {
        return self::relevantSegments($index, $event) !== [];
    }

    /**
     * Los segmentos que dependen de este evento (R8: solo esos se recalculan para el contacto).
     *
     * @param  list<array{id: string, events: list<string>, kinds: list<string>}>  $index
     * @return list<string>
     */
    public static function relevantSegments(array $index, string $event): array
    {
        if (str_starts_with($event, 'segment.')) {
            return []; // evita cascadas: lo de segmentos que dependen de segmentos lo resuelve el recálculo nocturno
        }
        $ids = [];
        foreach ($index as $entry) {
            if (self::dependsOn($entry, $event)) {
                $ids[] = $entry['id'];
            }
        }

        return $ids;
    }

    /** @param array{id: string, events: list<string>, kinds: list<string>} $entry */
    private static function dependsOn(array $entry, string $event): bool
    {
        if (in_array($event, $entry['events'], true)) {
            return true;
        }
        $kinds = $entry['kinds'];

        return match (true) {
            str_starts_with($event, 'order.') => array_intersect($kinds, ['purchase', 'attribute', 'cart']) !== [],
            str_starts_with($event, 'cart.') || str_starts_with($event, 'checkout.') => in_array('cart', $kinds, true)
                || (in_array($event, self::PROFILE_EVENTS, true) && in_array('attribute', $kinds, true)),
            in_array($event, self::PROFILE_EVENTS, true) => in_array('attribute', $kinds, true),
            str_starts_with($event, 'consent.') => in_array('consent', $kinds, true),
            str_starts_with($event, 'message.') || $event === 'whatsapp.inbound' => in_array('messaging', $kinds, true),
            str_starts_with($event, 'lead.') => in_array('lead', $kinds, true),
            str_starts_with($event, 'appointment.') => in_array('service', $kinds, true),
            self::isLoyaltyEvent($event) => in_array('loyalty', $kinds, true),
            default => false,
        };
    }

    /** Eventos que mueven puntos, saldo, nivel o referidos (Fase 3). */
    private static function isLoyaltyEvent(string $event): bool
    {
        return str_starts_with($event, 'points.') || str_starts_with($event, 'wallet.') || str_starts_with($event, 'referral.') || $event === 'tier.changed';
    }
}
