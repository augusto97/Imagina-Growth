<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Classifiers;

use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Data\InboundClassification;
use App\Domain\Inbox\Support\HeuristicInboundClassifier;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;

/**
 * Clasificador de entrantes con IA (inteligencia.md §6.6, Claude Haiku 5.5):
 * intención (compra, soporte, queja, baja u otra), sentimiento y confianza. Las
 * palabras de baja ya las resolvió `InboundHandler`; la IA cubre las variantes
 * («ya no me manden nada», «borren mi número»). Sin IA disponible, o si falla,
 * responde el clasificador por palabras.
 */
final class AiInboundClassifier implements InboundClassifier
{
    public function __construct(private readonly AiGateway $ai, private readonly HeuristicInboundClassifier $heuristic) {}

    public function classify(string $workspaceId, string $text): InboundClassification
    {
        $fallback = $this->heuristic->classify($workspaceId, $text);
        if ($fallback->optOut() || trim($text) === '' || ! $this->ai->available($workspaceId)) {
            return $fallback;
        }
        // La bandeja y el oyente de bajas clasifican el mismo entrante: una sola llamada al modelo.
        $key = RedisKey::for($workspaceId, 'ai:inbound:'.md5($text));
        $cached = Cache::get($key);
        if (is_array($cached) && isset($cached['intent'], $cached['sentiment'], $cached['confidence'])) {
            return new InboundClassification($cached['intent'], $cached['sentiment'], (float) $cached['confidence'], 'ai');
        }
        try {
            $result = $this->ai->generate(AiRequest::ask($workspaceId, 'inbound_classifier', self::system(), 'Mensaje: '.mb_substr($text, 0, 1500), self::schema()));
            $json = $result->json ?? [];
            $intent = in_array($json['intent'] ?? null, InboundClassification::INTENTS, true) ? (string) $json['intent'] : null;
            $sentiment = in_array($json['sentiment'] ?? null, InboundClassification::SENTIMENTS, true) ? (string) $json['sentiment'] : null;
            if ($intent === null || $sentiment === null || $result->refused()) {
                return $fallback;
            }

            /** @var 'purchase'|'support'|'complaint'|'optout'|'other' $intent */
            /** @var 'positive'|'neutral'|'negative' $sentiment */
            $classification = new InboundClassification($intent, $sentiment, max(0.0, min(1.0, (float) ($json['confidence'] ?? 0))), 'ai');
            Cache::put($key, $classification->toArray(), now()->addMinutes(10));

            return $classification;
        } catch (AiUnavailable) {
            return $fallback;
        }
    }

    public static function system(): string
    {
        return 'Clasificas mensajes de WhatsApp que los clientes le escriben a una tienda en línea de Latinoamérica. '
            .'intent: purchase (quiere comprar, pregunta precio, stock, envío o medios de pago), support (un pedido hecho, cambios, garantías, facturas), '
            .'complaint (queja o reclamo), optout (pide que no le escriban más, que lo saquen de la lista o que borren su número; no lo es si solo dice «no» a una pregunta), other. '
            .'sentiment: positive, neutral o negative. confidence: de 0 a 1, qué tan seguro estás de la intención; usa menos de 0,8 si es ambiguo.';
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'intent' => ['type' => 'string', 'enum' => InboundClassification::INTENTS],
                'sentiment' => ['type' => 'string', 'enum' => InboundClassification::SENTIMENTS],
                'confidence' => ['type' => 'number'],
            ],
            'required' => ['intent', 'sentiment', 'confidence'],
            'additionalProperties' => false,
        ];
    }
}
