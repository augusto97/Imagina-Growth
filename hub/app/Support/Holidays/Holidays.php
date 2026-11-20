<?php

declare(strict_types=1);

namespace App\Support\Holidays;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Festivos por país (docs/cumplimiento.md §4.2, §6 y §7): Colombia, México y
 * Venezuela. Los usan SendPolicy (no sale marketing en festivo), la agenda
 * (se cierra sola), los plazos de las solicitudes de datos y los datos de prueba.
 *
 * Las excepciones que publique el Gobierno (un festivo decretado o movido) se
 * agregan en config/holidays.php sin desplegar código.
 */
final class Holidays
{
    /** @var array<string, array<string, string>> caché por país y año */
    private static array $cache = [];

    public static function isHoliday(string $country, CarbonInterface $date): bool
    {
        return isset(self::forYear($country, (int) $date->year)[$date->format('Y-m-d')]);
    }

    /**
     * @return array<string, string> fecha Y-m-d => nombre
     */
    public static function forYear(string $country, int $year): array
    {
        $country = strtoupper($country);
        $key = $country.$year;
        if (! isset(self::$cache[$key])) {
            $list = match ($country) {
                'CO' => self::colombia($year),
                'MX' => self::mexico($year),
                'VE' => self::venezuela($year),
                default => [],
            };
            foreach (self::overrides()[$country][$year] ?? [] as $date => $name) {
                if ($name === null) {
                    unset($list[$date]);
                } else {
                    $list[$date] = $name;
                }
            }
            ksort($list);
            self::$cache[$key] = $list;
        }

        return self::$cache[$key];
    }

    /**
     * Las claves de año son enteros: PHP convierte "2026" en 2026 como clave.
     *
     * @return array<string, array<int, array<string, string|null>>>
     */
    private static function overrides(): array
    {
        $out = [];
        foreach (config()->array('holidays.overrides', []) as $country => $years) {
            foreach (is_array($years) ? $years : [] as $year => $dates) {
                foreach (is_array($dates) ? $dates : [] as $date => $name) {
                    $out[(string) $country][(int) $year][(string) $date] = is_string($name) ? $name : null;
                }
            }
        }
        // Tabla de excepciones que edita el admin de la plataforma (cumplimiento.md §4.2): manda sobre la configuración.
        try {
            foreach (DB::table('holiday_overrides')->get(['country', 'date', 'is_holiday', 'name']) as $row) {
                $date = substr((string) $row->date, 0, 10);
                $out[strtoupper((string) $row->country)][(int) substr($date, 0, 4)][$date] = $row->is_holiday ? (string) ($row->name ?: __('Festivo')) : null;
            }
        } catch (Throwable) {
            // Sin base de datos (p. ej. pruebas unitarias): solo la configuración.
        }

        return $out;
    }

    private static function day(int $year, int $month, int $day): CarbonImmutable
    {
        return new CarbonImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** Domingo de Pascua (algoritmo anónimo gregoriano / Meeus). */
    public static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return self::day($year, $month, $day);
    }

    /**
     * Colombia: Ley 51 de 1983 (Ley Emiliani) traslada al lunes siguiente los
     * festivos que no caen en lunes, salvo los fijos.
     *
     * @return array<string, string>
     */
    private static function colombia(int $year): array
    {
        $out = [];
        $add = function (CarbonImmutable $d, string $name) use (&$out): void {
            $out[$d->format('Y-m-d')] = $name;
        };
        $monday = fn (CarbonImmutable $d) => $d->isMonday() ? $d : $d->next(CarbonInterface::MONDAY);
        $date = fn (int $m, int $d) => self::day($year, $m, $d);

        // Fijos.
        $add($date(1, 1), 'Año Nuevo');
        $add($date(5, 1), 'Día del Trabajo');
        $add($date(7, 20), 'Día de la Independencia');
        $add($date(8, 7), 'Batalla de Boyacá');
        $add($date(12, 8), 'Inmaculada Concepción');
        $add($date(12, 25), 'Navidad');
        // Trasladables al lunes.
        $add($monday($date(1, 6)), 'Reyes Magos');
        $add($monday($date(3, 19)), 'San José');
        $add($monday($date(6, 29)), 'San Pedro y San Pablo');
        $add($monday($date(8, 15)), 'Asunción de la Virgen');
        $add($monday($date(10, 12)), 'Día de la Raza');
        $add($monday($date(11, 1)), 'Todos los Santos');
        $add($monday($date(11, 11)), 'Independencia de Cartagena');
        // Ligados a la Pascua.
        $easter = self::easter($year);
        $add($easter->subDays(3), 'Jueves Santo');
        $add($easter->subDays(2), 'Viernes Santo');
        $add($easter->addDays(43), 'Ascensión del Señor');
        $add($easter->addDays(64), 'Corpus Christi');
        $add($easter->addDays(71), 'Sagrado Corazón');

        return $out;
    }

    /**
     * México: días de descanso obligatorio del artículo 74 de la Ley Federal
     * del Trabajo.
     *
     * @return array<string, string>
     */
    private static function mexico(int $year): array
    {
        $nth = fn (int $month, int $n) => self::day($year, $month, 1)->nthOfMonth($n, CarbonInterface::MONDAY);
        $out = [
            sprintf('%d-01-01', $year) => 'Año Nuevo',
            $nth(2, 1)->format('Y-m-d') => 'Día de la Constitución',
            $nth(3, 3)->format('Y-m-d') => 'Natalicio de Benito Juárez',
            sprintf('%d-05-01', $year) => 'Día del Trabajo',
            sprintf('%d-09-16', $year) => 'Día de la Independencia',
            $nth(11, 3)->format('Y-m-d') => 'Día de la Revolución',
            sprintf('%d-12-25', $year) => 'Navidad',
        ];
        if (($year - 2024) % 6 === 0) {
            $out[sprintf('%d-10-01', $year)] = 'Transmisión del Poder Ejecutivo Federal';
        }

        return $out;
    }

    /**
     * Venezuela: días feriados nacionales del artículo 184 de la Ley Orgánica
     * del Trabajo, los Trabajadores y las Trabajadoras (G.O. Extraordinaria
     * N.º 6.076 del 7 de mayo de 2012) —1 de enero, lunes y martes de Carnaval,
     * Jueves y Viernes Santo, 1 de mayo y 24, 25 y 31 de diciembre— más los de
     * la Ley de Fiestas Nacionales (G.O. N.º 29.541 del 22 de junio de 1971):
     * 19 de abril, 24 de junio, 5 y 24 de julio y 12 de octubre. No se
     * trasladan. Los que decrete el Gobierno (hasta tres al año, literal d) van
     * como excepción en config/holidays.php o en `holiday_overrides`.
     *
     * @return array<string, string>
     */
    private static function venezuela(int $year): array
    {
        $date = fn (int $m, int $d): string => sprintf('%04d-%02d-%02d', $year, $m, $d);
        $easter = self::easter($year);

        return [
            $date(1, 1) => 'Año Nuevo',
            $easter->subDays(48)->format('Y-m-d') => 'Lunes de Carnaval',
            $easter->subDays(47)->format('Y-m-d') => 'Martes de Carnaval',
            $easter->subDays(3)->format('Y-m-d') => 'Jueves Santo',
            $easter->subDays(2)->format('Y-m-d') => 'Viernes Santo',
            $date(4, 19) => 'Declaración de la Independencia',
            $date(5, 1) => 'Día del Trabajador',
            $date(6, 24) => 'Batalla de Carabobo',
            $date(7, 5) => 'Día de la Independencia',
            $date(7, 24) => 'Natalicio del Libertador',
            $date(10, 12) => 'Día de la Resistencia Indígena',
            $date(12, 24) => 'Nochebuena',
            $date(12, 25) => 'Navidad',
            $date(12, 31) => 'Fin de Año',
        ];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
