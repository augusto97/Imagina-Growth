<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Support;

use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Data\InboundClassification;
use App\Domain\Messaging\Inbound\InboundHandler;
use Illuminate\Support\Str;

/**
 * Clasificación por palabras clave, sin IA: la baja usa las mismas palabras que
 * `InboundHandler`; lo demás busca señales simples de compra, soporte y queja.
 */
final class HeuristicInboundClassifier implements InboundClassifier
{
    private const PURCHASE = ['precio', 'cuanto', 'cuesta', 'vale', 'comprar', 'disponible', 'hay stock', 'tienen', 'envio', 'domicilio', 'pagar', 'link de pago', 'quiero'];

    private const SUPPORT = ['pedido', 'orden', 'guia', 'seguimiento', 'cuando llega', 'cambio', 'devolucion', 'garantia', 'factura', 'ayuda'];

    private const COMPLAINT = ['queja', 'reclamo', 'pesimo', 'mal servicio', 'no ha llegado', 'estafa', 'nunca llego', 'roto', 'danado', 'molesto', 'inaceptable'];

    private const POSITIVE = ['gracias', 'excelente', 'genial', 'perfecto', 'me encanto', 'super'];

    public function classify(string $workspaceId, string $text): InboundClassification
    {
        if (InboundHandler::unsubscribeKeyword($text) !== null) {
            return new InboundClassification('optout', 'neutral', 0.95, 'heuristic');
        }
        $t = ' '.trim((string) preg_replace('/[^a-z0-9 ]+/', ' ', strtolower(Str::ascii($text)))).' ';
        $has = fn (array $words) => collect($words)->contains(fn (string $w) => str_contains($t, ' '.$w.' ') || str_contains($t, ' '.$w));
        $intent = match (true) {
            $has(self::COMPLAINT) => 'complaint',
            $has(self::SUPPORT) => 'support',
            $has(self::PURCHASE) => 'purchase',
            default => 'other',
        };
        $sentiment = $intent === 'complaint' ? 'negative' : ($has(self::POSITIVE) ? 'positive' : 'neutral');

        return new InboundClassification($intent, $sentiment, $intent === 'other' ? 0.3 : 0.6, 'heuristic');
    }
}
