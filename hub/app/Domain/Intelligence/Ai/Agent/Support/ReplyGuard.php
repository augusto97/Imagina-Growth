<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Agent\Support;

use App\Domain\Intelligence\Ai\Support\NumberValidator;

/**
 * «No inventa precios ni stock» en código, no solo en el prompt (M11):
 *
 * 1. Toda cifra de la respuesta debe estar en lo que devolvieron las herramientas
 *    en este turno, en las políticas del negocio o, si es un entero pequeño
 *    (cantidades, hasta 999), en lo que escribió la persona.
 * 2. Si la respuesta habla de precio (un monto que no es de las políticas,
 *    «cuesta», «precio») o de stock («unidades», «agotado», «quedan»), en el turno
 *    tuvo que haber una consulta en vivo exitosa (`get_product` o
 *    `create_cart_link`).
 */
final class ReplyGuard
{
    private const MONEY = '/(?:\$|US\$|COP|MXN)\s?\d/u';

    private const PRICE_WORDS = '/\b(precio|cuesta|cuestan|valor|vale|valen)\b/iu';

    private const STOCK_WORDS = '/\b(agotad[oa]s?|existencias|sin stock|en stock|hay stock|quedan|inventario)\b|\d+\s+unidades?\b/iu';

    public function __construct(private readonly NumberValidator $numbers) {}

    /**
     * @param  list<array{name: string, input: array<string, mixed>, output: array<string, mixed>|null, error: string|null}>  $toolCalls
     * @param  array<string, string>  $policies
     * @param  list<string>  $customerTexts
     * @return array{ok: bool, unknown: list<string>, needs_lookup: bool, checked: int}
     */
    public function check(string $reply, array $toolCalls, array $policies, array $customerTexts): array
    {
        $facts = array_values(array_filter(array_map(fn (array $c) => $c['output'], $toolCalls)));
        $facts[] = ['policies' => $policies];
        $facts[] = ['customer' => $this->customerNumbers($customerTexts)];
        $validation = $this->numbers->validate($reply, $facts);

        $lookedUp = collect($toolCalls)->contains(fn (array $c) => in_array($c['name'], ['get_product', 'create_cart_link'], true)
            && $c['error'] === null && ($c['output']['found'] ?? $c['output']['ok'] ?? true) !== false);
        // Si todas las cifras son de las políticas (p. ej. «el envío cuesta $12.000»), no es un precio de producto.
        $onlyPolicyNumbers = $this->numbers->validate($reply, [$policies])->ok();
        $claimsPrice = ! $onlyPolicyNumbers && (preg_match(self::MONEY, $reply) === 1 || preg_match(self::PRICE_WORDS, $reply) === 1);
        $claimsStock = preg_match(self::STOCK_WORDS, $reply) === 1;
        $needsLookup = ($claimsPrice || $claimsStock) && ! $lookedUp;

        return ['ok' => $validation->ok() && ! $needsLookup, 'unknown' => $validation->unknown, 'needs_lookup' => $needsLookup, 'checked' => count($validation->checked)];
    }

    /**
     * @param  list<string>  $texts
     * @return list<int>
     */
    private function customerNumbers(array $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            if (preg_match_all('/(?<![\d.,$])\d{1,3}(?![\d.,])/u', $text, $m) > 0) {
                foreach ($m[0] as $n) {
                    $out[] = (int) $n;
                }
            }
        }

        return array_values(array_unique($out));
    }
}
