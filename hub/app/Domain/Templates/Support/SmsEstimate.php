<?php

declare(strict_types=1);

namespace App\Domain\Templates\Support;

use App\Domain\Messaging\Support\ContactLinkToken;
use App\Domain\Messaging\Support\SmsText;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Cuánto puede llegar a ocupar un SMS escrito con Liquid (modulos.md M08): cada variable cuenta con su largo máximo
 * realista, cada enlace con el largo del enlace rastreado que de verdad sale (`{links}/c/XXXXXXXX`), los bloques
 * `{% if %}` suman todas sus ramas y los `{% for %}` su `limit` (o 3 vueltas). La misma cuenta está en la SPA
 * (`features/templates/sms.ts`) y los casos compartidos en `tests/fixtures/sms/cases.json`.
 *
 * Las variables cuentan como letras de GSM-7: si el nombre de alguien trae «á», al enviar se cambia por «a» cuando
 * hace falta para caber (SmsText::forSending). El texto fijo cuenta tal cual se escribió.
 */
final class SmsEstimate
{
    /** Largo máximo realista de cada variable (caracteres). */
    public const LENGTHS = [
        'contacto.nombre' => 20, 'contacto.apellido' => 20, 'contacto.nombre_completo' => 40, 'contacto.ciudad' => 20,
        'contacto.pedidos' => 4, 'contacto.puntos' => 7, 'contacto.nivel' => 12,
        'tienda.direccion' => 50, 'tienda.contacto' => 30,
        'carrito.subtotal' => 14, 'carrito.total' => 14, 'carrito.cantidad_items' => 3,
        'cupon.codigo' => 16, 'cupon.descuento' => 8, 'cupon.vence' => 28,
        'pedido.numero' => 8, 'pedido.total' => 14, 'pedido.metodo_pago' => 20,
        'producto.nombre' => 40, 'producto.precio' => 14,
        'cita.servicio' => 30, 'cita.fecha' => 28, 'cita.hora' => 8, 'cita.lugar' => 20, 'cita.duracion' => 10,
        'lead.servicio' => 30, 'lead.etapa' => 20, 'cotizacion.servicio' => 30, 'cotizacion.valor' => 14,
        'evento.nombre' => 30, 'evento.fecha' => 28, 'evento.hora' => 8,
    ];

    /** Por cómo termina la variable (los de un `{% for %}`: `item.nombre`, `p.precio`). */
    public const SUFFIXES = [
        'nombre' => 40, 'precio' => 14, 'total' => 14, 'subtotal' => 14, 'valor' => 14, 'fecha' => 28, 'hora' => 8,
        'cantidad' => 3, 'codigo' => 16,
    ];

    /** Cualquier otra variable. */
    public const DEFAULT_LENGTH = 20;

    /** Lo que ocupa una cifra con `| moneda` y una fecha con `| fecha` (la corta, `| fecha: 'corta'`). */
    public const MONEY = 14;

    public const DATE = 28;

    public const DATE_SHORT = 12;

    /** Vueltas que se cuentan de un `{% for %}` sin `limit`. */
    public const LOOP_TIMES = 3;

    /** Un enlace rastreado lleva un código de 8 caracteres (LinkCode). */
    private const LINK_CODE = 8;

    /**
     * @return array{link_length: int, unsubscribe_length: int, store_name_length: int}
     */
    public static function limits(Workspace $workspace): array
    {
        $base = ContactLinkToken::base();
        // La baja y las preferencias no se acortan: su token firmado es largo (no conviene en un SMS: «Responde BAJA»).
        $unsubscribe = ContactLinkToken::unsubscribeUrl($workspace->id, str_repeat('0', 26), 'sms', str_repeat('0', 26));

        return [
            'link_length' => mb_strlen($base.'/c/') + self::LINK_CODE,
            'unsubscribe_length' => mb_strlen($unsubscribe),
            'store_name_length' => max(1, mb_strlen(trim((string) $workspace->name))),
        ];
    }

    /**
     * @param  array{link_length: int, unsubscribe_length: int, store_name_length: int}  $limits
     * @return array{encoding: 'GSM-7'|'UCS-2', length: int, segments: int, single_limit: int, part_limit: int, offenders: list<string>, has_variables: bool}
     */
    public static function worstCase(string $body, array $limits): array
    {
        $text = self::expand($body, $limits);

        return SmsText::measure($text) + ['has_variables' => preg_match('/\{\{|\{%/', $body) === 1];
    }

    /**
     * @return array{encoding: 'GSM-7'|'UCS-2', length: int, segments: int, single_limit: int, part_limit: int, offenders: list<string>, has_variables: bool}
     */
    public static function forWorkspace(string $body, Workspace $workspace): array
    {
        return self::worstCase($body, self::limits($workspace));
    }

    /**
     * El texto del peor caso: variables y enlaces como letras «x» de su largo máximo.
     *
     * @param  array{link_length: int, unsubscribe_length: int, store_name_length: int}  $limits
     */
    public static function expand(string $body, array $limits): string
    {
        $text = (string) preg_replace('/\{%-?\s*comment\s*-?%\}.*?\{%-?\s*endcomment\s*-?%\}/s', '', $body);
        $text = (string) preg_replace_callback('/\{%-?\s*for\s+[^%]*?-?%\}(.*?)\{%-?\s*endfor\s*-?%\}/s', function (array $m): string {
            $times = preg_match('/limit:\s*(\d+)/', $m[0], $limit) === 1 ? max(0, (int) $limit[1]) : self::LOOP_TIMES;

            return str_repeat($m[1], $times);
        }, $text);
        $text = (string) preg_replace('/\{%.*?%\}/s', '', $text);
        $text = (string) preg_replace_callback('/\{\{(.*?)\}\}/s', fn (array $m): string => str_repeat('x', self::expression($m[1], $limits)), $text);

        // Los enlaces escritos tal cual salen rastreados (LinkRewriter::text), salvo la baja y las preferencias.
        return (string) preg_replace_callback('#https?://[^\s<>"\')\]]+#i', function (array $m) use ($limits): string {
            $base = ContactLinkToken::base();
            if (str_starts_with($m[0], $base.'/u/') || str_starts_with($m[0], $base.'/preferencias/') || str_starts_with($m[0], $base.'/c/')) {
                return $m[0];
            }

            return str_repeat('x', $limits['link_length']);
        }, $text);
    }

    /**
     * Largo máximo de lo que pinta `{{ … }}`: la variable o el texto entre comillas, con sus filtros.
     *
     * @param  array{link_length: int, unsubscribe_length: int, store_name_length: int}  $limits
     */
    public static function expression(string $expression, array $limits): int
    {
        $parts = self::splitFilters($expression);
        $length = self::value(trim((string) array_shift($parts)), $limits);
        foreach ($parts as $filter) {
            [$name, $arg] = array_pad(array_map('trim', explode(':', $filter, 2)), 2, null);
            $argLength = $arg !== null && $arg !== '' ? self::value($arg, $limits) : 0;
            $length = match ($name) {
                'default' => max($length, $argLength),
                'moneda', 'money' => self::MONEY,
                'fecha', 'date' => $arg !== null && str_contains($arg, 'corta') ? self::DATE_SHORT : self::DATE,
                'append', 'prepend' => $length + $argLength,
                'truncate' => $arg !== null && is_numeric(trim($arg, " '\"")) ? min($length, (int) trim($arg, " '\"")) : $length,
                default => $length,
            };
        }

        return $length;
    }

    /** @param  array{link_length: int, unsubscribe_length: int, store_name_length: int}  $limits */
    private static function value(string $value, array $limits): int
    {
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^([\'"])(.*)\1$/s', $value, $m) === 1) {
            return mb_strlen($m[2]);
        }
        if (is_numeric($value)) {
            return mb_strlen($value);
        }
        $path = strtolower($value);
        if ($path === 'enlaces.baja' || $path === 'enlaces.preferencias') {
            return $limits['unsubscribe_length'];
        }
        $last = substr($path, (int) strrpos('.'.$path, '.'));
        if (str_starts_with($path, 'enlaces.') || str_contains($last, 'url') || str_contains($last, 'enlace')) {
            return $limits['link_length'];
        }
        if ($path === 'tienda.nombre') {
            return $limits['store_name_length'];
        }

        return self::LENGTHS[$path] ?? self::SUFFIXES[$last] ?? self::DEFAULT_LENGTH;
    }

    /** @return list<string> la variable y cada filtro, sin partir lo que está entre comillas */
    private static function splitFilters(string $expression): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        foreach (mb_str_split($expression) as $char) {
            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;
            } elseif ($char === '|') {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $parts[] = $current;

        return $parts;
    }
}
