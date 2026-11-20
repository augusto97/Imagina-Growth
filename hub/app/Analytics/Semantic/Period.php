<?php

declare(strict_types=1);

namespace App\Analytics\Semantic;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Rango de fechas en la zona horaria del espacio de trabajo, con su
 * comparación y granularidad. Las fronteras en UTC son [fromUtc, toUtc).
 */
final class Period
{
    public function __construct(
        public readonly CarbonImmutable $from,   // inicio del primer día (zona del espacio de trabajo)
        public readonly CarbonImmutable $to,     // inicio del día siguiente al último
        public readonly string $timezone,
        public readonly string $granularity,     // day | week | month
        public readonly ?Period $compare = null,
        public readonly string $compareMode = 'previous',
    ) {}

    public static function make(string $timezone, ?string $from, ?string $to, string $compare = 'previous', ?string $granularity = null): self
    {
        $tz = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
        $end = $to !== null && $to !== '' ? CarbonImmutable::parse($to, $tz)->startOfDay()->addDay() : CarbonImmutable::now($tz)->startOfDay()->addDay();
        $start = $from !== null && $from !== '' ? CarbonImmutable::parse($from, $tz)->startOfDay() : $end->subDays(30);
        if ($start >= $end) {
            throw new InvalidArgumentException('El rango de fechas no es válido.');
        }
        if ($start->diffInDays($end) > 800) {
            $start = $end->subDays(800);
        }
        $days = (int) round($start->diffInDays($end));
        $gran = in_array($granularity, ['day', 'week', 'month'], true) ? $granularity : ($days <= 62 ? 'day' : ($days <= 190 ? 'week' : 'month'));

        $cmp = match ($compare) {
            'previous' => new self($start->subDays($days), $start, $tz, $gran),
            'year' => new self($start->subYear(), $end->subYear(), $tz, $gran),
            default => null,
        };

        return new self($start, $end, $tz, $gran, $cmp, $compare);
    }

    public function fromUtc(): string
    {
        return $this->from->utc()->format('Y-m-d H:i:s');
    }

    public function toUtc(): string
    {
        return $this->to->utc()->format('Y-m-d H:i:s');
    }

    public function days(): int
    {
        return (int) round($this->from->diffInDays($this->to));
    }

    /** Desfase de la zona en segundos (LATAM no usa horario de verano; se toma al inicio del rango). */
    public function offsetSeconds(): int
    {
        return $this->from->getOffset();
    }

    /**
     * Claves de los periodos (Y-m-d del inicio) para rellenar series. Las semanas empiezan el lunes, como
     * `toMonday` en ClickHouse: Carbon toma el primer día del idioma (domingo en es_CO, es_MX y es_VE).
     *
     * @return list<string>
     */
    public function buckets(): array
    {
        $out = [];
        $cursor = match ($this->granularity) {
            'week' => $this->from->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $this->from->startOfMonth(),
            default => $this->from,
        };
        while ($cursor < $this->to) {
            $out[] = $cursor->toDateString();
            $cursor = match ($this->granularity) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->subDay()->toDateString(),
            'timezone' => $this->timezone,
            'granularity' => $this->granularity,
            'compare' => $this->compare === null ? null : [
                'mode' => $this->compareMode,
                'from' => $this->compare->from->toDateString(),
                'to' => $this->compare->to->subDay()->toDateString(),
            ],
        ];
    }
}
