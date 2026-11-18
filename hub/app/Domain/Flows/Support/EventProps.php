<?php

declare(strict_types=1);

namespace App\Domain\Flows\Support;

/**
 * Propiedades de un evento guardadas en el contexto de un run: sin datos
 * personales (correo, teléfono, nombres, direcciones, notas, texto libre) y
 * acotadas en tamaño. Los nodos y las plantillas solo necesitan pedido,
 * carrito, producto, montos y respuestas por botón.
 */
final class EventProps
{
    private const PII = [
        'email', 'phone', 'first_name', 'last_name', 'billing', 'shipping', 'customer_note', 'note', 'notes', 'ip',
        'user_agent', 'address', 'address_1', 'address_2', 'postcode', 'document', 'customer_ref', 'text', 'other',
        'consents', 'wa_id', 'from',
    ];

    /**
     * @param  array<mixed>  $props
     * @return array<string, mixed>
     */
    public static function sanitize(array $props, int $depth = 0): array
    {
        $out = [];
        foreach ($props as $key => $value) {
            $key = (string) $key;
            if (in_array(strtolower($key), self::PII, true)) {
                continue;
            }
            if (is_array($value)) {
                if ($depth >= 4) {
                    continue;
                }
                $value = array_is_list($value) ? array_slice($value, 0, 20) : $value;
                $clean = self::sanitize($value, $depth + 1);
                $out[$key] = array_is_list($value) ? array_values($clean) : $clean;
            } elseif (is_string($value)) {
                $out[$key] = mb_substr($value, 0, 500);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
