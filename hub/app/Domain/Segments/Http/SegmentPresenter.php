<?php

declare(strict_types=1);

namespace App\Domain\Segments\Http;

use App\Domain\Segments\Library\SegmentLibrary;
use App\Domain\Segments\Models\Segment;

final class SegmentPresenter
{
    /**
     * @param  array{points: list<int>, change: int|null, days: int}|null  $trend  tamaño de los últimos días (SegmentTrends)
     * @return array<string, mixed>
     */
    public static function make(Segment $s, bool $withDefinition = false, ?array $trend = null): array
    {
        $data = [
            'id' => $s->publicId(),
            'name' => $s->name,
            'description' => $s->description,
            'folder' => $s->folder,
            'kind' => $s->kind,
            'is_system' => $s->is_system,
            'system_key' => $s->system_key,
            // Para quién es (los del sistema): la lista muestra solo los del tipo de negocio.
            'audience' => SegmentLibrary::audienceOf($s->system_key),
            'member_count' => $s->member_count,
            'revenue_ws' => (string) $s->revenue_ws,
            'channel_counts' => $s->channel_counts ?? ['email' => 0, 'whatsapp' => 0],
            'last_computed_at' => $s->last_computed_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
            'trend' => $trend,
        ];
        if ($withDefinition) {
            $data['definition'] = $s->definition;
        }

        return $data;
    }
}
