<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Classifiers;

use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use App\Domain\Reviews\Contracts\ReviewClassifier;
use App\Domain\Reviews\Support\HeuristicReviewClassifier;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Moderación de reseñas con IA (inteligencia.md §6.6, Claude Haiku 5.5): lenguaje
 * ofensivo, spam y datos personales. Suma sus banderas a las de las heurísticas
 * (nunca quita una); sin IA disponible o si falla, quedan las heurísticas. Los
 * correos y teléfonos ya los marca la heurística y se quitan del texto que va al
 * modelo.
 */
final class AiReviewModerator implements ReviewClassifier
{
    private const FLAGS = ['offensive', 'spam', 'personal_data'];

    public function __construct(
        private readonly AiGateway $ai,
        private readonly HeuristicReviewClassifier $heuristic,
        private readonly CurrentWorkspace $current,
    ) {}

    public function classify(?string $title, ?string $body, string $authorName = ''): array
    {
        $base = $this->heuristic->classify($title, $body, $authorName);
        $text = trim(($title ?? '')."\n".($body ?? ''));
        if ($text === '' || ! $this->current->has() || ! $this->ai->available($this->current->id())) {
            return $base;
        }
        try {
            $result = $this->ai->generate(AiRequest::ask($this->current->id(), 'review_moderator', self::system(), "Reseña:\n".PiiScrubber::scrub(mb_substr($text, 0, 3000)), self::schema()));
            $json = $result->json ?? [];
            if ($result->refused()) {
                return $base;
            }
        } catch (AiUnavailable) {
            return $base;
        }
        $flags = $base['flags'];
        $details = $base['details'];
        foreach (self::FLAGS as $flag) {
            if (($json[$flag] ?? false) === true && ! in_array($flag, $flags, true)) {
                $flags[] = $flag;
                $details[$flag] = array_values(array_filter([...($details[$flag] ?? []), mb_substr((string) ($json['motivo'] ?? ''), 0, 200)]));
            }
        }

        return ['flags' => $flags, 'details' => $details, 'engine' => 'ai+heuristic'];
    }

    public static function system(): string
    {
        return 'Moderas reseñas de productos de una tienda en línea de Latinoamérica. Marca offensive si hay insultos, groserías, discriminación o amenazas; '
            .'spam si es publicidad, enlaces, texto sin relación con el producto o repetido; personal_data si trae datos personales (direcciones, documentos de identidad, '
            .'nombres completos de terceros, cuentas). Una crítica dura al producto o a la tienda NO es ofensiva: la calificación nunca es motivo. '
            .'motivo: una frase corta en español, vacía si no marcaste nada.';
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'offensive' => ['type' => 'boolean'],
                'spam' => ['type' => 'boolean'],
                'personal_data' => ['type' => 'boolean'],
                'motivo' => ['type' => 'string'],
            ],
            'required' => ['offensive', 'spam', 'personal_data', 'motivo'],
            'additionalProperties' => false,
        ];
    }
}
