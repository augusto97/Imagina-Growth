<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Support;

use Carbon\CarbonImmutable;

/**
 * Calendario comercial LATAM precargado por país (modulos.md M10), con ideas de
 * campaña. Las fechas se calculan por año con reglas (día fijo, n-ésimo día de la
 * semana del mes o relativas al Black Friday).
 *
 * **[verificar]** Hot Sale (AMVO), Buen Fin y los CyberLunes de Colombia (CCCE)
 * los fija cada año su organizador: aquí van con la regla de los últimos años.
 */
final class CommercialCalendar
{
    /**
     * @var array<string, array{name: string, countries: list<string>, rule: array<string, int|string>, days: int, lead: int, ideas: list<string>}>
     *
     * rule: fixed {month, day} · nth {month, n, dow (1 = lunes … 7 = domingo; n = -1 es el último)} ·
     * black_friday {offset (días)} · buen_fin · hot_sale. `days` = duración; `lead` = días antes para empezar a comunicar.
     */
    private const EVENTS = [
        'regreso_clases_a' => ['name' => 'Regreso a clases (calendario A)', 'countries' => ['CO'], 'rule' => ['type' => 'fixed', 'month' => 1, 'day' => 19], 'days' => 14, 'lead' => 14, 'ideas' => ['Listas escolares armadas por grado', 'Envío gratis en compras de útiles', 'Combo morral + cuadernos']],
        'regreso_clases_mx' => ['name' => 'Regreso a clases', 'countries' => ['MX'], 'rule' => ['type' => 'nth', 'month' => 8, 'n' => -1, 'dow' => 1], 'days' => 14, 'lead' => 21, 'ideas' => ['Listas de útiles por grado', 'Meses sin intereses en tecnología escolar', 'Uniformes y calzado escolar']],
        'regreso_clases_ve' => ['name' => 'Regreso a clases', 'countries' => ['VE'], 'rule' => ['type' => 'fixed', 'month' => 9, 'day' => 16], 'days' => 14, 'lead' => 21, 'ideas' => ['Útiles y uniformes', 'Pagos en cuotas', 'Combos por grado']],
        'dia_nino_mx' => ['name' => 'Día del Niño', 'countries' => ['MX'], 'rule' => ['type' => 'fixed', 'month' => 4, 'day' => 30], 'days' => 1, 'lead' => 10, 'ideas' => ['Guía de regalos por edad', 'Envoltura gratis']],
        'dia_madre' => ['name' => 'Día de la Madre', 'countries' => ['CO', 'VE'], 'rule' => ['type' => 'nth', 'month' => 5, 'n' => 2, 'dow' => 7], 'days' => 1, 'lead' => 15, 'ideas' => ['Guía de regalos por presupuesto', 'Fecha límite de envío para llegar a tiempo', 'Recordatorio a quien compró regalo el año pasado']],
        'dia_madre_mx' => ['name' => 'Día de la Madre', 'countries' => ['MX'], 'rule' => ['type' => 'fixed', 'month' => 5, 'day' => 10], 'days' => 1, 'lead' => 15, 'ideas' => ['Guía de regalos por presupuesto', 'Fecha límite de envío para llegar el 10 de mayo', 'Tarjeta de regalo de última hora']],
        'hot_sale' => ['name' => 'Hot Sale', 'countries' => ['MX'], 'rule' => ['type' => 'hot_sale'], 'days' => 9, 'lead' => 7, 'ideas' => ['Acceso anticipado para clientes VIP', 'Descuento escalonado por día', 'Meses sin intereses']],
        'dia_padre' => ['name' => 'Día del Padre', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'nth', 'month' => 6, 'n' => 3, 'dow' => 7], 'days' => 1, 'lead' => 14, 'ideas' => ['Regalos por pasatiempo', 'Combos de regalo con envoltura', 'Último día para pedir con entrega a tiempo']],
        'prima_junio' => ['name' => 'Pago de prima (junio)', 'countries' => ['CO'], 'rule' => ['type' => 'fixed', 'month' => 6, 'day' => 30], 'days' => 1, 'lead' => 15, 'ideas' => ['«Date un gusto con tu prima»: productos de mayor valor', 'Financiación o pago en cuotas', 'Recuperar carritos de productos caros']],
        'amor_amistad' => ['name' => 'Amor y Amistad', 'countries' => ['CO'], 'rule' => ['type' => 'nth', 'month' => 9, 'n' => 3, 'dow' => 6], 'days' => 1, 'lead' => 14, 'ideas' => ['Regalos para el «amigo secreto» por precio', 'Dos por uno para compartir', 'Mensaje de cariño a los clientes fieles']],
        'halloween' => ['name' => 'Halloween', 'countries' => ['CO', 'MX'], 'rule' => ['type' => 'fixed', 'month' => 10, 'day' => 31], 'days' => 1, 'lead' => 14, 'ideas' => ['Disfraces y decoración', 'Dulces y empaques temáticos']],
        'buen_fin' => ['name' => 'El Buen Fin', 'countries' => ['MX'], 'rule' => ['type' => 'buen_fin'], 'days' => 4, 'lead' => 10, 'ideas' => ['Acceso anticipado para clientes VIP', 'Meses sin intereses', 'Recordatorio el último día']],
        'black_friday' => ['name' => 'Black Friday', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'black_friday', 'offset' => 0], 'days' => 3, 'lead' => 10, 'ideas' => ['Acceso anticipado para VIP (sin regalar descuento a los campeones)', 'Cuenta regresiva por WhatsApp a quien lo pidió', 'Excluir a quien compró en los últimos 7 días']],
        'cyberlunes' => ['name' => 'CyberLunes', 'countries' => ['CO'], 'rule' => ['type' => 'black_friday', 'offset' => 3], 'days' => 1, 'lead' => 5, 'ideas' => ['Ofertas solo en línea por 24 horas', 'Envío gratis el día del evento']],
        'prima_diciembre' => ['name' => 'Pago de prima (diciembre)', 'countries' => ['CO'], 'rule' => ['type' => 'fixed', 'month' => 12, 'day' => 20], 'days' => 1, 'lead' => 15, 'ideas' => ['Regalos de Navidad con la prima', 'Productos de mayor valor en cuotas']],
        'navidad' => ['name' => 'Navidad', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'fixed', 'month' => 12, 'day' => 24], 'days' => 1, 'lead' => 24, 'ideas' => ['Guía de regalos y fecha límite de envío', 'Tarjetas de regalo de última hora', 'Novenas (Colombia): ideas para compartir en familia']],
    ];

    /**
     * Eventos del año para el país, ordenados por fecha.
     *
     * @return list<array{key: string, name: string, date: string, ends: string, starts_communicating: string, ideas: list<string>}>
     */
    public static function forYear(int $year, string $country): array
    {
        $country = strtoupper($country);
        $out = [];
        foreach (self::EVENTS as $key => $event) {
            if (! in_array($country, $event['countries'], true)) {
                continue;
            }
            $date = self::resolve($event['rule'], $year);
            $out[] = [
                'key' => $key,
                'name' => __($event['name']),
                'date' => $date->toDateString(),
                'ends' => $date->addDays(max(0, $event['days'] - 1))->toDateString(),
                'starts_communicating' => $date->subDays($event['lead'])->toDateString(),
                'ideas' => array_map(fn (string $i) => __($i), $event['ideas']),
            ];
        }
        usort($out, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $out;
    }

    /** @param array<string, int|string> $rule */
    public static function resolve(array $rule, int $year): CarbonImmutable
    {
        return match ($rule['type']) {
            'fixed' => CarbonImmutable::create($year, (int) $rule['month'], (int) $rule['day']),
            'nth' => self::nth($year, (int) $rule['month'], (int) $rule['n'], (int) $rule['dow']),
            // Viernes después del cuarto jueves de noviembre (el de Estados Unidos, que se adoptó en LATAM).
            'black_friday' => self::nth($year, 11, 4, 4)->addDays(1 + (int) ($rule['offset'] ?? 0)),
            // De viernes a lunes, terminando el tercer lunes de noviembre (Día de la Revolución trasladado).
            'buen_fin' => self::nth($year, 11, 3, 1)->subDays(3),
            // Último lunes de mayo, nueve días.
            'hot_sale' => self::nth($year, 5, -1, 1),
            default => CarbonImmutable::create($year, 1, 1),
        };
    }

    /** n-ésimo día de la semana ISO del mes (n = -1: el último). */
    private static function nth(int $year, int $month, int $n, int $dow): CarbonImmutable
    {
        if ($n < 0) {
            $day = CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay();
            while ($day->dayOfWeekIso !== $dow) {
                $day = $day->subDay();
            }

            return $day;
        }
        $day = CarbonImmutable::create($year, $month, 1);
        while ($day->dayOfWeekIso !== $dow) {
            $day = $day->addDay();
        }

        return $day->addWeeks($n - 1);
    }
}
