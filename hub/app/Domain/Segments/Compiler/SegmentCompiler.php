<?php

declare(strict_types=1);

namespace App\Domain\Segments\Compiler;

use App\Analytics\Ch;
use App\Domain\Segments\Definition\Fields;
use App\Domain\Segments\Models\Segment;
use App\Support\Ids\PrefixedId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compila el AST de un segmento (hub/CLAUDE.md §6) a una consulta sobre
 * `contacts`:
 *  - atributo, compra, predicción, consentimiento y segmento → SQL de MariaDB;
 *  - evento y carrito abandonado → SQL de ClickHouse que devuelve contact_id
 *    (por contact_id del evento o, si fue anónimo, por identity_map), que se
 *    combina como IN / NOT IN (en línea o con una tabla temporal).
 * Los valores del usuario siempre van como bindings o parámetros {x:Tipo}.
 * El AST debe venir de DefinitionValidator.
 */
final class SegmentCompiler
{
    /**
     * Consulta de los contactos del segmento (alias `c`), sin columnas elegidas.
     *
     * @param  array<string, mixed>  $ast
     */
    public function query(array $ast, CompileContext $ctx): Builder
    {
        $q = DB::table('contacts as c')
            ->where('c.workspace_id', $ctx->workspace->id)
            ->whereNull('c.merged_into_id')
            ->whereNull('c.deleted_at');
        if ($ctx->contactId !== null) {
            $q->where('c.id', $ctx->contactId);
        }
        $q->where(fn (Builder $w) => $this->group($w, $ast, $ctx));

        return $q;
    }

    /**
     * Tipos de evento que usa el AST (para el recálculo en tiempo real).
     *
     * @param  array<mixed>  $node
     * @return array{events: list<string>, kinds: list<string>}
     */
    public static function dependencies(array $node): array
    {
        $events = [];
        $kinds = [];
        $walk = function (array $n) use (&$walk, &$events, &$kinds): void {
            if (isset($n['type'])) {
                $kinds[] = (string) $n['type'];
                if ($n['type'] === 'event' && is_string($n['event'] ?? null)) {
                    $events[] = $n['event'];
                }
            }
            foreach (is_array($n['conditions'] ?? null) ? $n['conditions'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($node);

        return ['events' => array_values(array_unique($events)), 'kinds' => array_values(array_unique($kinds))];
    }

    /** @param array<string, mixed> $group */
    private function group(Builder $w, array $group, CompileContext $ctx): void
    {
        $boolean = ($group['op'] ?? 'and') === 'or' ? 'or' : 'and';
        $apply = function (Builder $inner) use ($group, $boolean, $ctx): void {
            foreach ($group['conditions'] ?? [] as $child) {
                $inner->where(function (Builder $x) use ($child, $ctx): void {
                    if (isset($child['conditions'])) {
                        $this->group($x, $child, $ctx);
                    } else {
                        $this->condition($x, $child, $ctx);
                    }
                }, null, null, $boolean);
            }
        };
        if (($group['negate'] ?? false) === true) {
            // NOT IN sobre la versión positiva: lógica de dos valores. Un NOT (…)
            // directo excluiría a quien tiene campos vacíos (NULL), p. ej. sin ciudad.
            $w->whereNotIn('c.id', fn (Builder $s) => $s->from('contacts as c')->select('c.id')
                ->where('c.workspace_id', $ctx->workspace->id)
                ->where($apply));
        } else {
            $w->where($apply);
        }
    }

    /** @param array<string, mixed> $c */
    private function condition(Builder $q, array $c, CompileContext $ctx): void
    {
        match ($c['type']) {
            'attribute' => $this->attribute($q, $c, $ctx),
            'purchase' => isset($c['field']) ? $this->purchaseAggregate($q, $c, $ctx) : $this->purchaseItems($q, $c, $ctx),
            'prediction' => $this->column($q, 'c.'.Fields::PREDICTIONS[$c['field']]['column'], Fields::PREDICTIONS[$c['field']]['type'], $c, $ctx),
            'consent' => $this->consent($q, $c),
            'segment' => $this->segmentRef($q, $c, $ctx),
            'event' => $this->eventCondition($q, $c, $ctx),
            'cart' => $this->cartCondition($q, $c, $ctx),
            default => $q->whereRaw('1 = 0'),
        };
    }

    // ── MariaDB ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $c */
    private function attribute(Builder $q, array $c, CompileContext $ctx): void
    {
        $field = (string) $c['field'];
        if (str_starts_with($field, 'custom.')) {
            $this->customAttribute($q, '$.'.substr($field, 7), (string) ($c['custom_type'] ?? 'string'), $c, $ctx);

            return;
        }
        match ($field) {
            'tags' => $this->tags($q, $c),
            'birthday' => $this->birthday($q, $c, $ctx),
            default => $this->column($q, 'c.'.Fields::ATTRIBUTES[$field]['column'], Fields::ATTRIBUTES[$field]['type'], $c, $ctx),
        };
    }

    /**
     * Atributo personalizado (contacts.custom_attributes): la ruta JSON va como
     * binding; el SQL es fijo por operador.
     *
     * @param  array<string, mixed>  $c
     */
    private function customAttribute(Builder $q, string $path, string $type, array $c, CompileContext $ctx): void
    {
        $v = $c['value'] ?? null;
        $op = (string) $c['operator'];
        $now = CarbonImmutable::now($ctx->timezone());
        if ($type === 'number') {
            match ($op) {
                'eq' => $q->whereRaw('CAST(JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) AS DECIMAL(20,6)) = ?', [$path, $v]),
                'neq' => $q->whereRaw('NOT (CAST(JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) AS DECIMAL(20,6)) <=> ?)', [$path, $v]),
                'gt' => $q->whereRaw('CAST(JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) AS DECIMAL(20,6)) > ?', [$path, $v]),
                'gte' => $q->whereRaw('CAST(JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) AS DECIMAL(20,6)) >= ?', [$path, $v]),
                'lt' => $q->whereRaw('CAST(JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) AS DECIMAL(20,6)) < ?', [$path, $v]),
                'lte' => $q->whereRaw('CAST(JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) AS DECIMAL(20,6)) <= ?', [$path, $v]),
                'between' => $q->whereRaw('CAST(JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) AS DECIMAL(20,6)) BETWEEN ? AND ?', [$path, ((array) $v)[0] ?? 0, ((array) $v)[1] ?? 0]),
                'is_set' => $q->whereRaw('JSON_EXTRACT(c.custom_attributes, ?) IS NOT NULL', [$path]),
                default => $q->whereRaw('JSON_EXTRACT(c.custom_attributes, ?) IS NULL', [$path]),
            };

            return;
        }
        // Texto, lista, fecha (AAAA-MM-DD) y sí/no se comparan como texto.
        [$op, $v] = match ($op) {
            'within_last_days' => ['between', [$now->subDays((int) $v)->toDateString(), $now->toDateString()]],
            'more_than_days_ago' => ['before', $now->subDays((int) $v)->toDateString()],
            'within_next_days' => ['between', [$now->toDateString(), $now->addDays((int) $v)->toDateString()]],
            default => [$op, $v],
        };
        match ($op) {
            'eq' => $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) = ?', [$path, $v]),
            'neq' => $q->whereRaw('NOT (JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) <=> ?)', [$path, $v]),
            'in' => $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) IN (SELECT value FROM JSON_TABLE(?, \'$[*]\' COLUMNS (value VARCHAR(255) PATH \'$\')) AS jt)', [$path, json_encode(array_values((array) $v))]),
            'not_in' => $q->whereRaw('NOT (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)), \'\') IN (SELECT value FROM JSON_TABLE(?, \'$[*]\' COLUMNS (value VARCHAR(255) PATH \'$\')) AS jt))', [$path, json_encode(array_values((array) $v))]),
            'contains' => $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) LIKE ?', [$path, '%'.$this->like((string) $v).'%']),
            'starts_with' => $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) LIKE ?', [$path, $this->like((string) $v).'%']),
            'before' => $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) < ?', [$path, $v]),
            'after' => $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) > ?', [$path, $v]),
            'between' => $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) BETWEEN ? AND ?', [$path, ((array) $v)[0] ?? '', ((array) $v)[1] ?? '']),
            'is_true' => $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) IN (\'true\', \'1\')', [$path]),
            'is_false' => $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)) IN (\'false\', \'0\')', [$path]),
            'is_set' => $q->whereRaw('COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)), \'\') <> \'\'', [$path]),
            default => $q->whereRaw('COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.custom_attributes, ?)), \'\') = \'\'', [$path]),
        };
    }

    /**
     * Comparación genérica de una columna de `contacts` según su tipo. La
     * columna sale siempre del catálogo de Fields, nunca del cliente.
     *
     * @param  array<string, mixed>  $c
     */
    private function column(Builder $q, string $col, string $type, array $c, CompileContext $ctx): void
    {
        $op = (string) $c['operator'];
        $v = $c['value'] ?? null;
        $isText = in_array($type, ['string', 'enum'], true);

        if ($type === 'date') {
            $this->dateColumn($q, $col, $op, $v, $ctx);

            return;
        }

        match ($op) {
            'is_set' => $isText ? $q->whereNotNull($col)->where($col, '<>', '') : $q->whereNotNull($col),
            'is_not_set' => $isText ? $q->where(fn (Builder $x) => $x->whereNull($col)->orWhere($col, '=', '')) : $q->whereNull($col),
            'eq' => $q->where($col, '=', $v),
            'neq' => $q->where(fn (Builder $x) => $x->where($col, '<>', $v)->orWhereNull($col)),
            'in' => $q->whereIn($col, (array) $v),
            'not_in' => $q->where(fn (Builder $x) => $x->whereNotIn($col, (array) $v)->orWhereNull($col)),
            'contains' => $q->where($col, 'like', '%'.$this->like((string) $v).'%'),
            'starts_with' => $q->where($col, 'like', $this->like((string) $v).'%'),
            'gt' => $q->where($col, '>', $v),
            'gte' => $q->where($col, '>=', $v),
            'lt' => $q->where($col, '<', $v),
            'lte' => $q->where($col, '<=', $v),
            'between' => $q->whereBetween($col, [((array) $v)[0] ?? null, ((array) $v)[1] ?? null]),
            default => $q->whereRaw('1 = 0'),
        };
    }

    private function dateColumn(Builder $q, string $col, string $op, mixed $v, CompileContext $ctx): void
    {
        $tz = $ctx->timezone();
        $dayStart = fn (string $date) => CarbonImmutable::parse($date, $tz)->startOfDay()->utc();
        $now = CarbonImmutable::now('UTC');

        match ($op) {
            'is_set' => $q->whereNotNull($col),
            'is_not_set' => $q->whereNull($col),
            'before' => $q->where($col, '<', $dayStart((string) $v)),
            'after' => $q->where($col, '>=', $dayStart((string) $v)->addDay()),
            'between' => $q->where($col, '>=', $dayStart((string) ((array) $v)[0]))->where($col, '<', $dayStart((string) ((array) $v)[1])->addDay()),
            'within_last_days' => $q->where($col, '>=', $now->subDays((int) $v))->where($col, '<=', $now),
            'more_than_days_ago' => $q->where($col, '<', $now->subDays((int) $v)),
            'within_next_days' => $q->where($col, '>=', $now)->where($col, '<', $now->addDays((int) $v)),
            default => $q->whereRaw('1 = 0'),
        };
    }

    /** @param array<string, mixed> $c */
    private function tags(Builder $q, array $c): void
    {
        $json = json_encode(array_values((array) ($c['value'] ?? [])), JSON_UNESCAPED_UNICODE);
        match ($c['operator']) {
            'contains_any' => $q->whereRaw("JSON_OVERLAPS(COALESCE(c.tags, '[]'), ?)", [$json]),
            'contains_all' => $q->whereRaw("JSON_CONTAINS(COALESCE(c.tags, '[]'), ?)", [$json]),
            'not_contains' => $q->whereRaw("NOT JSON_OVERLAPS(COALESCE(c.tags, '[]'), ?)", [$json]),
            'is_set' => $q->whereRaw("JSON_LENGTH(COALESCE(c.tags, '[]')) > 0"),
            default => $q->whereRaw("JSON_LENGTH(COALESCE(c.tags, '[]')) = 0"),
        };
    }

    /** @param array<string, mixed> $c */
    private function birthday(Builder $q, array $c, CompileContext $ctx): void
    {
        $today = CarbonImmutable::now($ctx->timezone());
        match ($c['operator']) {
            'this_month' => $q->whereRaw('MONTH(c.birthday) = ?', [$today->month]),
            'within_next_days' => (function () use ($q, $today, $c): void {
                $from = $today->format('m-d');
                $to = $today->addDays((int) $c['value'])->format('m-d');
                $from <= $to
                    ? $q->whereRaw("DATE_FORMAT(c.birthday, '%m-%d') BETWEEN ? AND ?", [$from, $to])
                    : $q->where(fn (Builder $x) => $x->whereRaw("DATE_FORMAT(c.birthday, '%m-%d') >= ?", [$from])->orWhereRaw("DATE_FORMAT(c.birthday, '%m-%d') <= ?", [$to]));
            })(),
            'is_set' => $q->whereNotNull('c.birthday'),
            default => $q->whereNull('c.birthday'),
        };
    }

    /** @param array<string, mixed> $c */
    private function purchaseAggregate(Builder $q, array $c, CompileContext $ctx): void
    {
        $field = (string) $c['field'];
        $def = Fields::PURCHASE_FIELDS[$field];
        $window = $c['window'] ?? null;
        if (is_array($window) && ($window['kind'] ?? 'ever') !== 'ever') {
            // Contado sobre los pedidos pagados de la ventana (subconsulta correlacionada: los contactos sin pedidos dan 0).
            [$from, $to] = $this->windowRange($window, $ctx);
            $sub = function (Builder $s) use ($field, $from, $to): void {
                $s->from('orders as o')
                    ->whereColumn('o.workspace_id', 'c.workspace_id')
                    ->whereColumn('o.contact_id', 'c.id')
                    ->where('o.is_paid', true);
                $field === 'orders_count' ? $s->selectRaw('COUNT(*)') : $s->selectRaw('COALESCE(SUM(o.total_ws), 0)');
                if ($from !== null) {
                    $s->whereRaw('COALESCE(o.paid_at, o.created_at) >= ?', [$from]);
                }
                if ($to !== null) {
                    $s->whereRaw('COALESCE(o.paid_at, o.created_at) < ?', [$to]);
                }
            };
            $v = $c['value'] ?? null;
            match ($c['operator']) {
                'eq' => $q->where($sub, '=', $v),
                'neq' => $q->where($sub, '<>', $v),
                'gt' => $q->where($sub, '>', $v),
                'gte' => $q->where($sub, '>=', $v),
                'lt' => $q->where($sub, '<', $v),
                'lte' => $q->where($sub, '<=', $v),
                'between' => $q->where($sub, '>=', ((array) $v)[0] ?? 0)->where($sub, '<=', ((array) $v)[1] ?? 0),
                default => $q->whereRaw('1 = 0'),
            };

            return;
        }
        $this->column($q, 'c.'.$def['column'], $def['type'], $c, $ctx);
    }

    /** @param array<string, mixed> $c */
    private function purchaseItems(Builder $q, array $c, CompileContext $ctx): void
    {
        [$threshold, $negate, $exact] = $this->countSemantics($c);
        if ($threshold === null) {
            return; // «al menos 0 veces»: todos
        }
        $scope = (string) $c['scope'];
        $ids = array_values((array) ($c['ids'] ?? []));
        [$from, $to] = $this->windowRange($c['window'] ?? ['kind' => 'ever'], $ctx);
        $ws = $ctx->workspace->id;

        $sub = function (Builder $s) use ($scope, $ids, $from, $to, $threshold, $exact, $ws): void {
            $s->from('orders as o')->select('o.contact_id')
                ->where('o.workspace_id', $ws)
                ->where('o.is_paid', true)
                ->whereNotNull('o.contact_id');
            if ($from !== null) {
                $s->whereRaw('COALESCE(o.paid_at, o.created_at) >= ?', [$from]);
            }
            if ($to !== null) {
                $s->whereRaw('COALESCE(o.paid_at, o.created_at) < ?', [$to]);
            }
            if (in_array($scope, ['product', 'category', 'brand'], true)) {
                $s->join('order_items as oi', 'oi.order_id', '=', 'o.id');
                match ($scope) {
                    'product' => $s->where(fn (Builder $x) => $x->whereIn('oi.external_product_id', $ids)->orWhereIn('oi.external_variation_id', $ids)),
                    'category' => $s->whereRaw('JSON_OVERLAPS(oi.category_ids, ?)', [json_encode($ids)]),
                    default => $s->whereIn('oi.brand', $ids),
                };
            } elseif ($scope === 'coupon') {
                $ids === []
                    ? $s->whereRaw('JSON_LENGTH(COALESCE(o.coupon_codes, ?)) > 0', ['[]'])
                    : $s->whereRaw('JSON_OVERLAPS(COALESCE(o.coupon_codes, ?), ?)', ['[]', json_encode($ids)]);
            } elseif ($scope === 'payment_method') {
                $s->whereIn('o.payment_method_id', $ids);
            }
            $s->groupBy('o.contact_id')->havingRaw('COUNT(DISTINCT o.id) '.($exact ? '=' : '>=').' ?', [$threshold]);
        };

        $negate ? $q->whereNotIn('c.id', $sub) : $q->whereIn('c.id', $sub);
    }

    /**
     * Contactos que pueden recibir marketing por el canal (consentimiento
     * vigente y sin supresión). Lo usan la vista previa y las estadísticas.
     */
    public function applyMarketingConsent(Builder $q, string $channel): void
    {
        $this->consent($q, ['channel' => $channel, 'purpose' => 'marketing', 'status' => 'granted']);
    }

    /** @param array<string, mixed> $c */
    private function consent(Builder $q, array $c): void
    {
        $channel = (string) $c['channel'];
        $identifier = $channel === 'email' ? 'c.email_bidx' : 'c.phone_bidx';
        $granted = function (Builder $x) use ($channel, $identifier, $c): void {
            $x->whereNotNull($identifier)
                ->whereExists(fn (Builder $s) => $s->from('contact_consents as cc')->selectRaw('1')
                    ->whereColumn('cc.workspace_id', 'c.workspace_id')
                    ->whereColumn('cc.contact_id', 'c.id')
                    ->where('cc.channel', $channel)
                    ->where('cc.purpose', (string) $c['purpose'])
                    ->where('cc.status', 'granted'))
                ->whereNotExists(fn (Builder $s) => $s->from('suppressions as sp')->selectRaw('1')
                    ->whereColumn('sp.workspace_id', 'c.workspace_id')
                    ->where('sp.channel', $channel)
                    ->whereColumn('sp.value_bidx', $identifier));
        };
        ($c['status'] ?? 'granted') === 'granted' ? $q->where($granted) : $q->whereNot($granted);
    }

    /** @param array<string, mixed> $c */
    private function segmentRef(Builder $q, array $c, CompileContext $ctx): void
    {
        $id = PrefixedId::decode(Segment::ID_PREFIX, (string) $c['segment_id']) ?? '';
        $sub = fn (Builder $s) => $s->from('segment_memberships')->select('contact_id')
            ->where('workspace_id', $ctx->workspace->id)
            ->where('segment_id', $id);
        ($c['operator'] ?? 'in') === 'in' ? $q->whereIn('c.id', $sub) : $q->whereNotIn('c.id', $sub);
    }

    // ── ClickHouse ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $c */
    private function eventCondition(Builder $q, array $c, CompileContext $ctx): void
    {
        [$threshold, $negate, $exact] = $this->countSemantics($c);
        if ($threshold === null) {
            return;
        }
        $params = ['ev' => (string) $c['event'], 'n' => $threshold];
        $where = ['e.event = {ev:String}'];
        [$from, $to] = $this->windowRange($c['window'] ?? ['kind' => 'ever'], $ctx);
        $this->chWindow($where, $params, $from, $to);
        foreach (array_values((array) ($c['filters'] ?? [])) as $i => $f) {
            $expr = in_array($f['prop'], Fields::EVENT_COLUMNS, true) ? 'e.'.$f['prop'] : 'toString(e.properties.'.$f['prop'].')';
            $name = 'f'.$i;
            match ($f['operator']) {
                'eq' => $where[] = "{$expr} = {{$name}:String}",
                'neq' => $where[] = "{$expr} != {{$name}:String}",
                'contains' => $where[] = "positionCaseInsensitiveUTF8({$expr}, {{$name}:String}) > 0",
                default => $where[] = "has({{$name}:Array(String)}, {$expr})",
            };
            $params[$name] = $f['operator'] === 'in' ? self::chArray((array) $f['value']) : (string) $f['value'];
        }
        $measure = match ($c['measure'] ?? 'events') {
            'sessions' => "uniqExactIf(sid, sid != '')",
            'days' => 'uniqExact(toDate(at, {tz:String}))',
            default => 'count()',
        };
        $params['tz'] = $ctx->timezone();

        $sql = 'SELECT cid FROM ('.$this->resolvedEvents($where, $ctx, $params).') WHERE cid != \'\' GROUP BY cid HAVING '
            .$measure.($exact ? ' = ' : ' >= ').'{n:UInt64}';
        $this->applySet($q, $this->chIds($sql, $params, $ctx), $negate, $ctx);
    }

    /** @param array<string, mixed> $c */
    private function cartCondition(Builder $q, array $c, CompileContext $ctx): void
    {
        [$from, $to] = $this->windowRange($c['window'], $ctx);
        $minutes = (int) $ctx->workspace->setting('abandoned_cart_minutes', 45);
        $params = [
            'carts' => self::chArray(Fields::CART_EVENTS),
            'orders' => self::chArray(['order.created', 'order.paid']),
            'cutoff' => CarbonImmutable::now('UTC')->subMinutes(max(1, $minutes))->format('Y-m-d H:i:s.v'),
        ];
        $where = ['(has({carts:Array(String)}, e.event) OR has({orders:Array(String)}, e.event))'];
        $this->chWindow($where, $params, $from, $to);

        $sql = 'SELECT cid FROM ('.$this->resolvedEvents($where, $ctx, $params).') WHERE cid != \'\' GROUP BY cid HAVING '
            .'maxIf(at, has({carts:Array(String)}, ev)) > maxIf(at, has({orders:Array(String)}, ev)) '
            .'AND maxIf(at, has({carts:Array(String)}, ev)) < {cutoff:DateTime64(3, \'UTC\')}';
        $this->applySet($q, $this->chIds($sql, $params, $ctx), false, $ctx);
    }

    /**
     * Eventos con su contacto resuelto: el del evento o, si llegó anónimo, el de identity_map.
     *
     * @param  list<string>  $where
     * @param  array<string, scalar|null>  $params
     */
    private function resolvedEvents(array $where, CompileContext $ctx, array &$params): string
    {
        if ($ctx->contactId !== null) {
            $where[] = '(e.contact_id = {rc:String} OR has({ra:Array(String)}, e.anonymous_id))';
            $params['rc'] = $ctx->contactId;
            $params['ra'] = self::chArray($ctx->anonymousIds);
        }

        return "SELECT if(e.contact_id != '', e.contact_id, m.contact_id) AS cid, e.session_id AS sid, e.occurred_at AS at, e.event AS ev "
            .'FROM events AS e LEFT JOIN ('
            .'SELECT anonymous_id, argMax(contact_id, linked_at) AS contact_id FROM identity_map '
            .'WHERE workspace_id = {workspace_id:UInt32} GROUP BY anonymous_id'
            .') AS m ON e.anonymous_id = m.anonymous_id '
            .'WHERE e.workspace_id = {workspace_id:UInt32} AND '.implode(' AND ', $where);
    }

    /**
     * @param  list<string>  $where
     * @param  array<string, scalar|null>  $params
     */
    private function chWindow(array &$where, array &$params, ?CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        if ($from !== null) {
            $where[] = "e.occurred_at >= {from:DateTime64(3, 'UTC')}";
            $params['from'] = $from->format('Y-m-d H:i:s.v');
        }
        if ($to !== null) {
            $where[] = "e.occurred_at < {to:DateTime64(3, 'UTC')}";
            $params['to'] = $to->format('Y-m-d H:i:s.v');
        }
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return list<string>
     */
    private function chIds(string $sql, array $params, CompileContext $ctx): array
    {
        try {
            $rows = Ch::forWorkspace($ctx->workspace)->select(
                $sql.' SETTINGS max_execution_time = '.max(1, $ctx->timeoutSeconds),
                $params,
            );
        } catch (Throwable $e) {
            throw SegmentTimeout::looksLike($e) ? new SegmentTimeout($e) : $e;
        }

        return array_values(array_map(fn (array $r) => (string) $r['cid'], $rows));
    }

    /** @param list<string> $ids */
    private function applySet(Builder $q, array $ids, bool $negate, CompileContext $ctx): void
    {
        if ($ids === []) {
            $negate ? null : $q->whereRaw('1 = 0');

            return;
        }
        if (count($ids) <= CompileContext::INLINE_LIMIT) {
            $negate ? $q->whereNotIn('c.id', $ids) : $q->whereIn('c.id', $ids);

            return;
        }
        $table = $ctx->materialize($ids);
        $sub = fn (Builder $s) => $s->from($table)->select('contact_id');
        $negate ? $q->whereNotIn('c.id', $sub) : $q->whereIn('c.id', $sub);
    }

    // ── Comunes ─────────────────────────────────────────────────────────

    /**
     * Traduce el operador de conteo a [umbral, negado, exacto]:
     * ever = ≥ 1 · never = no(≥ 1) · count_gte n = ≥ n · count_lte n = no(≥ n+1) · count_eq n = (= n).
     * Umbral null = todos los contactos.
     *
     * @param  array<string, mixed>  $c
     * @return array{0: int|null, 1: bool, 2: bool}
     */
    private function countSemantics(array $c): array
    {
        $n = (int) ($c['value'] ?? 1);

        return match ($c['operator']) {
            'never' => [1, true, false],
            'count_gte' => $n <= 0 ? [null, false, false] : [$n, false, false],
            'count_lte' => [$n + 1, true, false],
            'count_eq' => $n === 0 ? [1, true, false] : [$n, false, true],
            default => [1, false, false],
        };
    }

    /**
     * @param  array<string, mixed>  $window
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} en UTC
     */
    private function windowRange(array $window, CompileContext $ctx): array
    {
        $tz = $ctx->timezone();

        return match ($window['kind'] ?? 'ever') {
            'last_days' => [CarbonImmutable::now('UTC')->subDays((int) $window['days']), null],
            'this_month' => [CarbonImmutable::now($tz)->startOfMonth()->utc(), null],
            'between' => [
                CarbonImmutable::parse((string) $window['from'], $tz)->startOfDay()->utc(),
                CarbonImmutable::parse((string) $window['to'], $tz)->startOfDay()->addDay()->utc(),
            ],
            default => [null, null],
        };
    }

    private function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * Literal de Array(String) para un parámetro de ClickHouse por HTTP.
     *
     * @param  array<mixed>  $values
     */
    public static function chArray(array $values): string
    {
        return '['.implode(',', array_map(
            fn ($v) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) (is_scalar($v) ? $v : ''))."'",
            array_values($values),
        )).']';
    }
}
