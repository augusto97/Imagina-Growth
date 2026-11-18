<?php

declare(strict_types=1);

namespace App\Domain\Flows\Support;

use App\Domain\Flows\Console\DateTriggersCommand;
use App\Domain\Flows\Graph\Filters;
use App\Domain\Flows\Models\Flow;
use App\Domain\Segments\Models\Segment;
use App\Ingest\Schema\SchemaRegistry;

/**
 * Valida y normaliza los ajustes de un flujo: disparador, meta, reentrada,
 * exclusiones y grupo de control (modulos.md M09, «Disparadores» y «Políticas»).
 */
final class FlowRules
{
    public const DATE_ATTRIBUTES = ['birthday', 'first_order_anniversary', 'expected_next_order_at', 'second_purchase_due', 'points_expiring_at', 'renewal_at'];

    public function __construct(private readonly SchemaRegistry $schemas) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> ajustes listos para guardar
     *
     * @throws InvalidFlow
     */
    public function normalize(array $data, ?Flow $flow = null): array
    {
        $errors = [];
        $out = [];
        if (array_key_exists('trigger', $data)) {
            $out['trigger'] = $this->trigger((array) $data['trigger'], $errors);
        }
        if (array_key_exists('goal', $data)) {
            $goal = $data['goal'];
            if ($goal === null || $goal === [] || ($goal['event'] ?? '') === '') {
                $out['goal'] = null;
            } elseif (! is_string($goal['event'] ?? null) || ! $this->schemas->isKnownEvent($goal['event']) || ! Filters::valid((array) ($goal['filters'] ?? []))) {
                $errors['goal'] = __('Elige un evento válido para la meta.');
            } else {
                $out['goal'] = ['event' => $goal['event'], 'filters' => array_values((array) ($goal['filters'] ?? []))];
            }
        }
        if (array_key_exists('reentry_policy', $data)) {
            $policy = (array) $data['reentry_policy'];
            $mode = $policy['mode'] ?? 'once';
            $days = (int) ($policy['days'] ?? 0);
            if (! in_array($mode, ['once', 'every_days', 'always'], true) || ($mode === 'every_days' && ($days < 1 || $days > 365))) {
                $errors['reentry_policy'] = __('Elige cuándo puede volver a entrar (una vez, cada 1 a 365 días o siempre).');
            } else {
                $out['reentry_policy'] = $mode === 'every_days' ? ['mode' => $mode, 'days' => $days] : ['mode' => $mode];
            }
        }
        if (array_key_exists('exclusions', $data)) {
            $ids = [];
            foreach ((array) $data['exclusions'] as $public) {
                $other = is_string($public) ? Flow::findByPublicId($public) : null;
                if ($other === null || ($flow !== null && $other->id === $flow->id)) {
                    $errors['exclusions'] = __('Uno de los flujos excluyentes no existe.');

                    continue;
                }
                $ids[] = $other->id;
            }
            $out['exclusions'] = array_values(array_unique($ids));
        }
        if (array_key_exists('holdout_pct', $data)) {
            $pct = $data['holdout_pct'];
            if (! is_numeric($pct) || (int) $pct < 0 || (int) $pct > 50) {
                $errors['holdout_pct'] = __('El grupo de control va de 0% a 50%.');
            } else {
                $out['holdout_pct'] = (int) $pct;
            }
        }
        if ($errors !== []) {
            throw new InvalidFlow($errors);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $t
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    private function trigger(array $t, array &$errors): array
    {
        $type = $t['type'] ?? null;
        switch ($type) {
            case 'event':
                $event = $t['event'] ?? null;
                if (! is_string($event) || ! $this->schemas->isKnownEvent($event) || str_starts_with($event, 'flow.')) {
                    $errors['trigger.event'] = __('Elige el evento que dispara el flujo.');
                }
                if (! Filters::valid((array) ($t['filters'] ?? []))) {
                    $errors['trigger.filters'] = __('Los filtros del disparador no son válidos.');
                }

                return ['type' => 'event', 'event' => $event, 'filters' => array_values((array) ($t['filters'] ?? []))];
            case 'segment':
                $segment = null;
                if (is_string($t['segment_id'] ?? null)) {
                    $segment = Segment::findByPublicId($t['segment_id']);
                } elseif (is_string($t['system_key'] ?? null)) {
                    $segment = Segment::query()->where('system_key', $t['system_key'])->first();
                }
                if ($segment === null || ! $segment->isDynamic()) {
                    $errors['trigger.segment_id'] = __('Elige un segmento dinámico.');
                }
                $on = ($t['on'] ?? 'entered') === 'exited' ? 'exited' : 'entered';

                return ['type' => 'segment', 'segment_id' => $segment?->publicId(), 'on' => $on, 'filters' => []];
            case 'date':
                $attribute = $t['attribute'] ?? null;
                $offset = (int) ($t['offset_days'] ?? 0);
                $hour = (int) ($t['hour'] ?? 9);
                if (! in_array($attribute, self::DATE_ATTRIBUTES, true) || $offset < -60 || $offset > 60 || $hour < 0 || $hour > 23) {
                    $errors['trigger.attribute'] = __('Elige la fecha, los días de diferencia (−60 a 60) y la hora.');
                }

                return ['type' => 'date', 'attribute' => $attribute, 'offset_days' => $offset, 'hour' => $hour];
            case 'threshold':
                $metric = $t['metric'] ?? null;
                if (! in_array($metric, DateTriggersCommand::METRICS, true) || ! in_array($t['operator'] ?? 'gte', ['gte', 'gt', 'lte', 'lt'], true) || ! is_numeric($t['value'] ?? null)) {
                    $errors['trigger.metric'] = __('Elige la métrica, la comparación y el valor del umbral.');
                }

                return ['type' => 'threshold', 'metric' => $metric, 'operator' => $t['operator'] ?? 'gte', 'value' => is_numeric($t['value'] ?? null) ? (float) $t['value'] : null];
            case 'manual':
            case 'webhook':
                return ['type' => $type];
            default:
                $errors['trigger.type'] = __('Elige cómo entran los contactos al flujo.');

                return ['type' => null];
        }
    }
}
