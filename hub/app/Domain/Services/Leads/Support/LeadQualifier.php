<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Support;

use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use Throwable;

/**
 * Calificación del mensaje del formulario con IA (inteligencia.md §4.9, Claude
 * Haiku): intención, urgencia y presupuesto mencionado → de −20 a +20 puntos con
 * una razón corta y visible. El mensaje va al modelo sin correos ni teléfonos y
 * nunca se guarda: del resultado solo quedan los puntos y la razón. Sin IA (o si
 * falla) devuelve null y el puntaje queda con las reglas.
 */
final class LeadQualifier
{
    /** Clave de la función de IA (config/igr-ai.php). [pendiente] `lead_qualifier` propia; mientras, la del clasificador de entrantes. */
    public const FEATURE = 'inbound_classifier';

    public function __construct(private readonly AiGateway $ai) {}

    /** @return array{points: int, reason: string, service: string|null}|null */
    public function qualify(string $workspaceId, string $message, ?string $service, int $max = 20): ?array
    {
        $message = trim($message);
        if ($message === '' || ! $this->ai->available($workspaceId)) {
            return null;
        }
        $prompt = ($service !== null ? 'Servicio elegido en el formulario: '.mb_substr($service, 0, 160)."\n" : '')
            .'Mensaje del formulario:'."\n".PiiScrubber::scrub(mb_substr($message, 0, 2000));
        try {
            $result = $this->ai->generate(AiRequest::ask($workspaceId, self::FEATURE, self::system($max), (string) $prompt, self::schema($max)));
        } catch (AiUnavailable) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        $json = $result->json;
        if ($result->refused() || ! is_array($json) || ! is_numeric($json['puntos'] ?? null)) {
            return null;
        }
        $reason = trim((string) PiiScrubber::scrub(mb_substr((string) ($json['razon'] ?? ''), 0, 200)));
        $guess = is_string($json['servicio'] ?? null) && trim($json['servicio']) !== '' ? mb_substr(trim($json['servicio']), 0, 160) : null;

        return ['points' => max(-$max, min($max, (int) round((float) $json['puntos']))), 'reason' => $reason, 'service' => $guess];
    }

    public static function system(int $max): string
    {
        return 'Calificas leads de un negocio de servicios en Latinoamérica a partir del mensaje que dejaron en un formulario. '
            ."Devuelve puntos de -{$max} a +{$max}: suma si hay intención clara de contratar, urgencia (fechas, «lo antes posible») o presupuesto "
            .'mencionado; resta si es spam, publicidad, una búsqueda de empleo, un proveedor ofreciendo algo o un mensaje sin intención de compra. '
            .'razon: una frase corta en español que explique los puntos, sin nombres, correos, teléfonos ni cifras que no estén en el mensaje. '
            .'servicio: el servicio que pide en pocas palabras, o vacío si no se sabe.';
    }

    /** @return array<string, mixed> */
    public static function schema(int $max): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'puntos' => ['type' => 'integer', 'minimum' => -$max, 'maximum' => $max],
                'intencion' => ['type' => 'string', 'enum' => ['alta', 'media', 'baja', 'ninguna']],
                'urgencia' => ['type' => 'boolean'],
                'presupuesto_mencionado' => ['type' => 'boolean'],
                'razon' => ['type' => 'string'],
                'servicio' => ['type' => 'string'],
            ],
            'required' => ['puntos', 'intencion', 'urgencia', 'presupuesto_mencionado', 'razon', 'servicio'],
            'additionalProperties' => false,
        ];
    }
}
