<?php

declare(strict_types=1);

namespace App\Domain\Segments\Definition;

use App\Domain\Segments\Models\Segment;
use App\Ingest\Schema\SchemaRegistry;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Valida y normaliza el AST de un segmento (hub/CLAUDE.md §6) con mensajes
 * claros en español. Debe correr con el espacio de trabajo activo: revisa los
 * atributos personalizados y las referencias a otros segmentos.
 */
final class DefinitionValidator
{
    public const MAX_DEPTH = 4;

    public const MAX_CONDITIONS = 40;

    private const COUNT_OPS = ['count_gte', 'count_lte', 'count_eq', 'ever', 'never'];

    private const PROP_PATH = '/^[a-z_][a-z0-9_]{0,40}(\.[a-z_][a-z0-9_]{0,40}){0,3}$/';

    /** @var array<string, string> */
    private array $errors = [];

    private int $leaves = 0;

    public function __construct(private readonly SchemaRegistry $schemas) {}

    /**
     * @param  array<mixed>  $definition
     * @return array<string, mixed> el AST normalizado
     *
     * @throws InvalidDefinition
     */
    public function validate(array $definition, ?string $selfSegmentId = null): array
    {
        $this->errors = [];
        $this->leaves = 0;
        $normalized = $this->group($definition, '', 1, $selfSegmentId);
        $this->finish();

        return $normalized;
    }

    /** @throws InvalidDefinition */
    private function finish(): void
    {
        if ($this->leaves === 0 && $this->errors === []) {
            $this->errors['conditions'] = __('Agrega al menos una condición.');
        }
        if ($this->leaves > self::MAX_CONDITIONS) {
            $this->errors[''] = __('Un segmento puede tener como máximo :max condiciones.', ['max' => self::MAX_CONDITIONS]);
        }
        if ($this->errors !== []) {
            throw new InvalidDefinition($this->errors);
        }
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>
     */
    private function group(array $node, string $path, int $depth, ?string $self): array
    {
        if ($depth > self::MAX_DEPTH) {
            $this->fail($path, __('Los grupos pueden anidarse hasta :max niveles.', ['max' => self::MAX_DEPTH]));

            return [];
        }
        $op = $node['op'] ?? 'and';
        if (! in_array($op, ['and', 'or'], true)) {
            $this->fail($this->join($path, 'op'), __('El grupo debe ser «y» (and) u «o» (or).'));
        }
        $conditions = $node['conditions'] ?? null;
        if (! is_array($conditions) || ! array_is_list($conditions)) {
            $this->fail($this->join($path, 'conditions'), __('El grupo no tiene condiciones.'));

            return ['op' => $op, 'conditions' => []];
        }
        $out = [];
        foreach ($conditions as $i => $child) {
            $childPath = $this->join($path, 'conditions.'.$i);
            if (! is_array($child)) {
                $this->fail($childPath, __('Condición inválida.'));

                continue;
            }
            $out[] = isset($child['op']) && ! isset($child['type'])
                ? $this->group($child, $childPath, $depth + 1, $self)
                : $this->condition($child, $childPath, $self);
        }
        if ($out === [] && $depth > 1) {
            $this->fail($this->join($path, 'conditions'), __('El grupo está vacío.'));
        }

        $group = ['op' => $op, 'conditions' => $out];
        if (($node['negate'] ?? false) === true) {
            $group['negate'] = true;
        }

        return $group;
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>
     */
    private function condition(array $c, string $path, ?string $self): array
    {
        $this->leaves++;
        $type = $c['type'] ?? null;

        return match ($type) {
            'attribute' => $this->attribute($c, $path),
            'purchase' => $this->purchase($c, $path),
            'event' => $this->event($c, $path),
            'cart' => $this->cart($c, $path),
            'prediction' => $this->prediction($c, $path),
            'consent' => $this->consent($c, $path),
            'segment' => $this->segmentRef($c, $path, $self),
            'messaging' => $this->later($path, __('La segmentación por mensajes (enviados, leídos, clics) llega en la Fase 2.')),
            'loyalty' => $this->later($path, __('La segmentación por fidelización (nivel, puntos, saldo) llega en la Fase 3.')),
            'service', 'lead' => $this->later($path, __('La segmentación por leads y citas llega en la Fase 5.')),
            default => $this->later($this->join($path, 'type'), __('Tipo de condición desconocido.')),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function later(string $path, string $message): array
    {
        $this->fail($path, $message);

        return [];
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>
     */
    private function attribute(array $c, string $path): array
    {
        $field = is_string($c['field'] ?? null) ? $c['field'] : '';
        if (str_starts_with($field, 'custom.')) {
            return $this->customAttribute($c, $path, substr($field, 7));
        }
        $def = Fields::ATTRIBUTES[$field] ?? null;
        if ($def === null) {
            $this->fail($this->join($path, 'field'), __('Atributo desconocido.'));

            return ['type' => 'attribute'];
        }

        return ['type' => 'attribute', 'field' => $field] + $this->operatorValue($c, $path, $def['type'], $def['values'] ?? null);
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>
     */
    private function customAttribute(array $c, string $path, string $key): array
    {
        if (! preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
            $this->fail($this->join($path, 'field'), __('Atributo personalizado inválido.'));

            return ['type' => 'attribute'];
        }
        $def = DB::table('custom_attribute_defs')
            ->where('workspace_id', app(CurrentWorkspace::class)->id())
            ->where('key', $key)
            ->first(['type', 'options', 'is_sensitive']);
        if ($def === null) {
            $this->fail($this->join($path, 'field'), __('El atributo personalizado «:key» no existe.', ['key' => $key]));

            return ['type' => 'attribute'];
        }
        if ((bool) $def->is_sensitive) {
            // cumplimiento.md §2: un atributo sensible no se usa en segmentos de marketing.
            $this->fail($this->join($path, 'field'), __('El atributo «:key» está marcado como sensible y no se puede usar en segmentos.', ['key' => $key]));

            return ['type' => 'attribute'];
        }
        $options = json_decode((string) ($def->options ?? 'null'), true);
        $values = is_array($options)
            ? array_values(array_map(fn ($o) => is_array($o) ? (string) ($o['value'] ?? '') : (is_scalar($o) ? (string) $o : ''), $options))
            : null;

        return ['type' => 'attribute', 'field' => 'custom.'.$key, 'custom_type' => Fields::customType((string) $def->type)]
            + $this->operatorValue($c, $path, Fields::customType((string) $def->type), $values);
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>
     */
    private function purchase(array $c, string $path): array
    {
        if (isset($c['field'])) {
            $field = is_string($c['field']) ? $c['field'] : '';
            $def = Fields::PURCHASE_FIELDS[$field] ?? null;
            if ($def === null) {
                $this->fail($this->join($path, 'field'), __('Dato de compra desconocido.'));

                return ['type' => 'purchase'];
            }
            $out = ['type' => 'purchase', 'field' => $field] + $this->operatorValue($c, $path, $def['type'], null);
            if (isset($c['window'])) {
                if (! in_array($field, ['orders_count', 'total_spent'], true)) {
                    $this->fail($this->join($path, 'window'), __('La ventana de tiempo solo aplica al número de pedidos y al gasto total.'));
                } else {
                    $out['window'] = $this->window($c['window'], $this->join($path, 'window'));
                }
            }

            return $out;
        }

        $scope = $c['scope'] ?? 'any';
        if (! in_array($scope, ['any', 'product', 'category', 'brand', 'coupon', 'payment_method'], true)) {
            $this->fail($this->join($path, 'scope'), __('Alcance de compra inválido.'));
            $scope = 'any';
        }
        $ids = $c['ids'] ?? [];
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 500) {
            $this->fail($this->join($path, 'ids'), __('La lista de opciones no es válida.'));
            $ids = [];
        }
        $ids = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 120) : '', $ids), fn ($v) => $v !== ''));
        if ($ids === [] && in_array($scope, ['product', 'category', 'brand', 'payment_method'], true)) {
            $this->fail($this->join($path, 'ids'), __('Elige al menos una opción.'));
        }

        return ['type' => 'purchase', 'scope' => $scope, 'ids' => $ids] + $this->countOperator($c, $path)
            + (isset($c['window']) ? ['window' => $this->window($c['window'], $this->join($path, 'window'))] : []);
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>
     */
    private function event(array $c, string $path): array
    {
        $event = is_string($c['event'] ?? null) ? $c['event'] : '';
        if (! $this->schemas->isKnownEvent($event)) {
            $this->fail($this->join($path, 'event'), __('El evento «:event» no existe en el catálogo.', ['event' => mb_substr($event, 0, 64)]));
        }
        $filters = [];
        $rawFilters = $c['filters'] ?? [];
        if (! is_array($rawFilters) || ! array_is_list($rawFilters) || count($rawFilters) > 5) {
            $this->fail($this->join($path, 'filters'), __('Se permiten hasta 5 filtros por evento.'));
            $rawFilters = [];
        }
        foreach ($rawFilters as $i => $f) {
            $fp = $this->join($path, 'filters.'.$i);
            $prop = is_array($f) && is_string($f['prop'] ?? null) ? $f['prop'] : '';
            if (! preg_match(self::PROP_PATH, $prop)) {
                $this->fail($this->join($fp, 'prop'), __('Propiedad inválida.'));

                continue;
            }
            $op = is_array($f) ? ($f['operator'] ?? 'eq') : 'eq';
            if (! in_array($op, ['eq', 'neq', 'in', 'contains'], true)) {
                $this->fail($this->join($fp, 'operator'), __('Operador inválido.'));

                continue;
            }
            $value = $f['value'] ?? null;
            if ($op === 'in') {
                if (! is_array($value) || $value === [] || count($value) > 200) {
                    $this->fail($this->join($fp, 'value'), __('Elige al menos un valor.'));

                    continue;
                }
                $value = array_values(array_map(fn ($v) => mb_substr((string) (is_scalar($v) ? $v : ''), 0, 300), $value));
            } elseif (! is_scalar($value) || (string) $value === '') {
                $this->fail($this->join($fp, 'value'), __('Escribe un valor.'));

                continue;
            } else {
                $value = mb_substr((string) $value, 0, 300);
            }
            $filters[] = ['prop' => $prop, 'operator' => $op, 'value' => $value];
        }
        $measure = $c['measure'] ?? 'events';
        if (! in_array($measure, ['events', 'sessions', 'days'], true)) {
            $this->fail($this->join($path, 'measure'), __('Medida inválida.'));
            $measure = 'events';
        }

        return ['type' => 'event', 'event' => $event, 'filters' => $filters, 'measure' => $measure] + $this->countOperator($c, $path)
            + (isset($c['window']) ? ['window' => $this->window($c['window'], $this->join($path, 'window'))] : []);
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>
     */
    private function cart(array $c, string $path): array
    {
        if (($c['operator'] ?? 'abandoned') !== 'abandoned') {
            $this->fail($this->join($path, 'operator'), __('Operador inválido.'));
        }
        $window = isset($c['window']) ? $this->window($c['window'], $this->join($path, 'window')) : ['kind' => 'last_days', 'days' => 7];
        if (($window['kind'] ?? null) === 'ever') {
            $this->fail($this->join($path, 'window'), __('Elige una ventana de tiempo para el carrito abandonado.'));
        }

        return ['type' => 'cart', 'operator' => 'abandoned', 'window' => $window];
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>
     */
    private function prediction(array $c, string $path): array
    {
        $field = is_string($c['field'] ?? null) ? $c['field'] : '';
        $def = Fields::PREDICTIONS[$field] ?? null;
        if ($def === null) {
            $this->fail($this->join($path, 'field'), __('Predicción desconocida.'));

            return ['type' => 'prediction'];
        }

        return ['type' => 'prediction', 'field' => $field] + $this->operatorValue($c, $path, $def['type'], $def['values'] ?? null);
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>
     */
    private function consent(array $c, string $path): array
    {
        $channel = $c['channel'] ?? null;
        if (! in_array($channel, ['email', 'whatsapp', 'sms'], true)) {
            $this->fail($this->join($path, 'channel'), __('Canal inválido.'));
            $channel = 'email';
        }
        $purpose = $c['purpose'] ?? 'marketing';
        if (! in_array($purpose, ['marketing', 'transactional'], true)) {
            $this->fail($this->join($path, 'purpose'), __('Finalidad inválida.'));
            $purpose = 'marketing';
        }
        $status = $c['status'] ?? 'granted';
        if (! in_array($status, ['granted', 'not_granted'], true)) {
            $this->fail($this->join($path, 'status'), __('Estado inválido.'));
            $status = 'granted';
        }

        return ['type' => 'consent', 'channel' => $channel, 'purpose' => $purpose, 'status' => $status];
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>
     */
    private function segmentRef(array $c, string $path, ?string $self): array
    {
        $publicId = is_string($c['segment_id'] ?? null) ? $c['segment_id'] : null;
        $id = PrefixedId::decode(Segment::ID_PREFIX, $publicId);
        $operator = $c['operator'] ?? 'in';
        if (! in_array($operator, ['in', 'not_in'], true)) {
            $this->fail($this->join($path, 'operator'), __('Operador inválido.'));
        }
        if ($id === null || ! Segment::query()->whereKey($id)->exists()) {
            $this->fail($this->join($path, 'segment_id'), __('El segmento no existe.'));

            return ['type' => 'segment'];
        }
        if ($self !== null && $this->references($id, $self, 0)) {
            $this->fail($this->join($path, 'segment_id'), __('Un segmento no puede depender de sí mismo.'));
        }

        return ['type' => 'segment', 'segment_id' => (string) $publicId, 'operator' => $operator];
    }

    /** ¿El segmento $id usa (directa o indirectamente) al segmento $target? */
    private function references(string $id, string $target, int $depth): bool
    {
        if ($id === $target) {
            return true;
        }
        if ($depth > 5) {
            return true; // cadenas demasiado largas se tratan como ciclo
        }
        $definition = Segment::query()->whereKey($id)->value('definition');
        $ast = is_string($definition) ? json_decode($definition, true) : $definition;
        foreach (self::segmentRefs(is_array($ast) ? $ast : []) as $ref) {
            $refId = PrefixedId::decode(Segment::ID_PREFIX, $ref);
            if ($refId !== null && $this->references($refId, $target, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * IDs públicos de los segmentos que usa un AST.
     *
     * @param  array<mixed>  $node
     * @return list<string>
     */
    public static function segmentRefs(array $node): array
    {
        $out = [];
        if (($node['type'] ?? null) === 'segment' && is_string($node['segment_id'] ?? null)) {
            $out[] = $node['segment_id'];
        }
        foreach (is_array($node['conditions'] ?? null) ? $node['conditions'] : [] as $child) {
            if (is_array($child)) {
                array_push($out, ...self::segmentRefs($child));
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $c
     * @return array{operator: string, value?: int}
     */
    private function countOperator(array $c, string $path): array
    {
        $op = $c['operator'] ?? 'ever';
        if (! in_array($op, self::COUNT_OPS, true)) {
            $this->fail($this->join($path, 'operator'), __('Operador inválido.'));

            return ['operator' => 'ever'];
        }
        if (in_array($op, ['ever', 'never'], true)) {
            return ['operator' => $op];
        }
        $value = $c['value'] ?? null;
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $this->fail($this->join($path, 'value'), __('Escribe un número de veces.'));

            return ['operator' => $op, 'value' => 1];
        }
        $value = (int) $value;
        if ($value < 0 || $value > 100000) {
            $this->fail($this->join($path, 'value'), __('El número de veces debe estar entre 0 y 100.000.'));
        }

        return ['operator' => $op, 'value' => $value];
    }

    /**
     * @param  array<mixed>  $c
     * @param  list<string>|null  $values  valores permitidos (enum)
     * @return array<string, mixed>
     */
    private function operatorValue(array $c, string $path, string $type, ?array $values): array
    {
        $op = $c['operator'] ?? null;
        if (! is_string($op) || ! in_array($op, Fields::operatorsFor($type), true)) {
            $this->fail($this->join($path, 'operator'), __('Operador inválido para este campo.'));

            return ['operator' => 'is_set'];
        }
        $vp = $this->join($path, 'value');
        $value = $c['value'] ?? null;

        switch ($op) {
            case 'is_set': case 'is_not_set': case 'this_month': case 'is_true': case 'is_false':
                return ['operator' => $op];
            case 'in': case 'not_in': case 'contains_any': case 'contains_all': case 'not_contains':
                if (! is_array($value) || $value === [] || count($value) > 500) {
                    $this->fail($vp, __('Elige al menos un valor.'));

                    return ['operator' => $op, 'value' => []];
                }
                $list = array_values(array_map(fn ($v) => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, 200), $value));
                if ($values !== null && array_diff($list, $values) !== []) {
                    $this->fail($vp, __('Hay valores que no son válidos para este campo.'));
                }

                return ['operator' => $op, 'value' => $list];
            case 'between':
                if (! is_array($value) || count($value) !== 2) {
                    $this->fail($vp, __('Escribe el valor inicial y el final.'));

                    return ['operator' => $op, 'value' => [0, 0]];
                }
                $a = $type === 'date' ? $this->date($value[0], $vp) : $this->number($value[0], $vp);
                $b = $type === 'date' ? $this->date($value[1], $vp) : $this->number($value[1], $vp);
                if ($a !== null && $b !== null && $a > $b) {
                    $this->fail($vp, __('El valor inicial debe ser menor que el final.'));
                }

                return ['operator' => $op, 'value' => [$a, $b]];
            case 'before': case 'after':
                return ['operator' => $op, 'value' => $this->date($value, $vp)];
            case 'within_last_days': case 'more_than_days_ago': case 'within_next_days':
                $days = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
                if ($days < 1 || $days > 3650) {
                    $this->fail($vp, __('Escribe un número de días entre 1 y 3.650.'));
                }

                return ['operator' => $op, 'value' => $days];
            case 'gt': case 'gte': case 'lt': case 'lte':
                return ['operator' => $op, 'value' => $this->number($value, $vp)];
            default: // eq, neq, contains, starts_with
                if ($type === 'number') {
                    return ['operator' => $op, 'value' => $this->number($value, $vp)];
                }
                if (! is_scalar($value) || trim((string) $value) === '') {
                    $this->fail($vp, __('Escribe un valor.'));

                    return ['operator' => $op, 'value' => ''];
                }
                $string = mb_substr(trim((string) $value), 0, 200);
                if ($values !== null && in_array($op, ['eq', 'neq'], true) && ! in_array($string, $values, true)) {
                    $this->fail($vp, __('El valor no es válido para este campo.'));
                }

                return ['operator' => $op, 'value' => $string];
        }
    }

    private function number(mixed $value, string $path): ?float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }
        $this->fail($path, __('Escribe un número.'));

        return null;
    }

    private function date(mixed $value, string $path): ?string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return $value;
        }
        $this->fail($path, __('Escribe una fecha válida (AAAA-MM-DD).'));

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function window(mixed $w, string $path): array
    {
        if (! is_array($w)) {
            $this->fail($path, __('Ventana de tiempo inválida.'));

            return ['kind' => 'ever'];
        }

        return match ($w['kind'] ?? null) {
            'ever' => ['kind' => 'ever'],
            'this_month' => ['kind' => 'this_month'],
            'last_days' => (function () use ($w, $path): array {
                $days = $w['days'] ?? null;
                if (! (is_int($days) || (is_string($days) && ctype_digit($days))) || (int) $days < 1 || (int) $days > 3650) {
                    $this->fail($this->join($path, 'days'), __('Escribe un número de días entre 1 y 3.650.'));

                    return ['kind' => 'last_days', 'days' => 30];
                }

                return ['kind' => 'last_days', 'days' => (int) $days];
            })(),
            'between' => (function () use ($w, $path): array {
                $from = $this->date($w['from'] ?? null, $this->join($path, 'from'));
                $to = $this->date($w['to'] ?? null, $this->join($path, 'to'));
                if ($from !== null && $to !== null && $from > $to) {
                    $this->fail($path, __('La fecha inicial debe ser anterior a la final.'));
                }

                return ['kind' => 'between', 'from' => $from, 'to' => $to];
            })(),
            default => (function () use ($path): array {
                $this->fail($path, __('Ventana de tiempo inválida.'));

                return ['kind' => 'ever'];
            })(),
        };
    }

    private function fail(string $path, string $message): void
    {
        $this->errors[$path] ??= $message;
    }

    private function join(string $path, string $suffix): string
    {
        return $path === '' ? $suffix : $path.'.'.$suffix;
    }
}
