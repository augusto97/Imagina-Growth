<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Audience;

use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Audiencia de una campaña (M10, paso 2): segmentos incluidos y excluidos más
 * reglas. Usa la membresía ya calculada de los segmentos (`segment_memberships`).
 *
 * Reglas (`rules_include` se combinan con Y; `rules_exclude` restan):
 * - `purchased_within_days` {days}: compró (pedido pagado) en los últimos N días.
 * - `messaged_within_days` {days, channel?}: recibió un mensaje en los últimos N días.
 * - `has_consent` {channel}: tiene consentimiento de marketing en el canal.
 * - `never_purchased`: nunca compró.
 */
final class AudienceQuery
{
    public const RULES = ['purchased_within_days', 'messaged_within_days', 'has_consent', 'never_purchased'];

    /**
     * Normaliza y valida la audiencia que llega de la SPA.
     *
     * @param  array<string, mixed>  $audience
     * @return array{segments_include: list<string>, segments_exclude: list<string>, all_contacts: bool, rules_include: list<array<string, mixed>>, rules_exclude: list<array<string, mixed>>}
     */
    public static function normalize(array $audience): array
    {
        $ids = fn (mixed $list): array => array_values(array_unique(array_filter(array_map('strval', is_array($list) ? $list : []), fn (string $id) => PrefixedId::decode('sg', $id) !== null)));
        $rules = function (mixed $list): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $rule) {
                if (! is_array($rule) || ! in_array($rule['type'] ?? null, self::RULES, true)) {
                    throw new InvalidArgumentException(__('Regla de audiencia desconocida.'));
                }
                $clean = ['type' => (string) $rule['type']];
                if (in_array($clean['type'], ['purchased_within_days', 'messaged_within_days'], true)) {
                    $clean['days'] = max(1, min(730, (int) ($rule['days'] ?? 7)));
                }
                if (isset($rule['channel']) && in_array($rule['channel'], ['email', 'whatsapp', 'sms'], true)) {
                    $clean['channel'] = (string) $rule['channel'];
                } elseif ($clean['type'] === 'has_consent') {
                    throw new InvalidArgumentException(__('La regla de consentimiento necesita un canal.'));
                }
                $out[] = $clean;
            }

            return $out;
        };

        return [
            'segments_include' => $ids($audience['segments_include'] ?? []),
            'segments_exclude' => $ids($audience['segments_exclude'] ?? []),
            'all_contacts' => (bool) ($audience['all_contacts'] ?? false),
            'rules_include' => $rules($audience['rules_include'] ?? []),
            'rules_exclude' => $rules($audience['rules_exclude'] ?? []),
        ];
    }

    /** ¿Tiene a quién enviar? (al menos un segmento incluido o «todos los contactos»). */
    public static function isDefined(array $audience): bool
    {
        return ! empty($audience['all_contacts']) || ! empty($audience['segments_include']);
    }

    /**
     * Contactos vigentes de la audiencia (alias `c`, sin columnas elegidas).
     *
     * @param  array<string, mixed>  $audience
     */
    public function query(Workspace $workspace, array $audience): Builder
    {
        $audience = self::normalize($audience);
        $q = DB::table('contacts as c')->where('c.workspace_id', $workspace->id)->whereNull('c.merged_into_id');
        if (! self::isDefined($audience)) {
            return $q->whereRaw('1 = 0');
        }
        $decode = fn (array $ids): array => array_values(array_filter(array_map(fn (string $id) => PrefixedId::decode('sg', $id), $ids)));
        if (! $audience['all_contacts']) {
            $include = $decode($audience['segments_include']);
            $q->whereExists(fn (Builder $s) => $s->from('segment_memberships as sm')->whereColumn('sm.contact_id', 'c.id')
                ->where('sm.workspace_id', $workspace->id)->whereIn('sm.segment_id', $include === [] ? ['-'] : $include));
        }
        $exclude = $decode($audience['segments_exclude']);
        if ($exclude !== []) {
            $q->whereNotExists(fn (Builder $s) => $s->from('segment_memberships as sx')->whereColumn('sx.contact_id', 'c.id')
                ->where('sx.workspace_id', $workspace->id)->whereIn('sx.segment_id', $exclude));
        }
        foreach ($audience['rules_include'] as $rule) {
            $this->applyRule($q, $workspace, $rule, false);
        }
        foreach ($audience['rules_exclude'] as $rule) {
            $this->applyRule($q, $workspace, $rule, true);
        }

        return $q;
    }

    public function count(Workspace $workspace, array $audience): int
    {
        return $this->query($workspace, $audience)->count();
    }

    /** @param array<string, mixed> $rule */
    private function applyRule(Builder $q, Workspace $workspace, array $rule, bool $exclude): void
    {
        $since = now()->subDays((int) ($rule['days'] ?? 0));
        $sub = match ($rule['type']) {
            'purchased_within_days' => fn (Builder $s) => $s->from('orders as o')->whereColumn('o.contact_id', 'c.id')
                ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true)->where('o.created_at', '>=', $since),
            'never_purchased' => fn (Builder $s) => $s->from('orders as o')->whereColumn('o.contact_id', 'c.id')
                ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true),
            'messaged_within_days' => fn (Builder $s) => $s->from('messages as m')->whereColumn('m.contact_id', 'c.id')
                ->where('m.workspace_id', $workspace->id)->where('m.direction', 'out')
                ->whereIn('m.status', ['queued', 'sending', 'sent', 'delivered', 'read'])
                ->where('m.created_at', '>=', $since)
                ->when(isset($rule['channel']), fn (Builder $w) => $w->where('m.channel', $rule['channel'])),
            'has_consent' => fn (Builder $s) => $s->from('contact_consents as cc')->whereColumn('cc.contact_id', 'c.id')
                ->where('cc.workspace_id', $workspace->id)->where('cc.channel', $rule['channel'])
                ->where('cc.purpose', 'marketing')->where('cc.status', 'granted'),
            default => throw new InvalidArgumentException('regla'),
        };
        // never_purchased es una negación: incluirla = NOT EXISTS.
        $negate = $rule['type'] === 'never_purchased' ? ! $exclude : $exclude;
        $negate ? $q->whereNotExists($sub) : $q->whereExists($sub);
    }
}
