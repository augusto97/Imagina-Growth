<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Brief;

use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Support\NumberValidator;
use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use Throwable;

/**
 * Lee la conversación con IA (función `lead_brief`, económica) y devuelve solo lo
 * que se puede comprobar en lo que escribió el cliente (LeadBrief::ground):
 * qué necesita, cantidad, ciudad, zona, para cuándo, presupuesto y la temperatura
 * con su razón y la frase que la respalda. Los correos y teléfonos se quitan antes
 * de enviar el texto al modelo. Sin IA, o si falla, devuelve null (no se inventa
 * nada para llenar el hueco).
 */
final class BriefExtractor
{
    public const FEATURE = 'lead_brief';

    /** Claves de la respuesta del modelo → campos de la ficha. */
    private const KEYS = ['necesidad' => 'need', 'cantidad' => 'quantity', 'ciudad' => 'city', 'zona' => 'zone', 'para_cuando' => 'timing', 'presupuesto' => 'budget'];

    private const TEMPERATURE = ['fria' => 'cold', 'tibia' => 'warm', 'caliente' => 'hot'];

    public function __construct(private readonly AiGateway $ai, private readonly NumberValidator $numbers) {}

    /**
     * @param  list<array{role: 'customer'|'business', text: string}>  $transcript  del más viejo al más nuevo
     * @return array{purchase: bool, fields: array<string, array{value: string, quote: string, reason?: string, index: int}>, discarded: list<string>}|null
     */
    public function extract(string $workspaceId, array $transcript, ?string $business = null): ?array
    {
        $customer = array_values(array_map(fn (array $m) => $m['text'], array_filter($transcript, fn (array $m) => $m['role'] === 'customer' && trim($m['text']) !== '')));
        if ($customer === [] || ! $this->ai->available($workspaceId)) {
            return null;
        }
        $lines = [];
        foreach ($transcript as $m) {
            $lines[] = ($m['role'] === 'customer' ? 'Cliente: ' : 'Negocio: ').PiiScrubber::scrub(mb_substr(trim($m['text']), 0, 1200));
        }
        $prompt = ($business !== null ? 'Negocio: '.mb_substr($business, 0, 120)."\n" : '')
            ."Conversación (la última línea es el mensaje más reciente):\n".mb_substr(implode("\n", $lines), -8000);
        try {
            $result = $this->ai->generate(AiRequest::ask($workspaceId, self::FEATURE, self::system(), $prompt, self::schema()));
        } catch (AiUnavailable) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        $json = $result->json;
        if ($result->refused() || ! is_array($json)) {
            return null;
        }

        $fields = [];
        $discarded = [];
        $values = is_array($json['campos'] ?? null) ? $json['campos'] : [];
        foreach (self::KEYS as $from => $to) {
            $item = is_array($values[$from] ?? null) ? $values[$from] : [];
            $value = trim((string) ($item['valor'] ?? ''));
            if ($value === '') {
                continue;
            }
            $value = mb_substr($value, 0, LeadBrief::TEXT_FIELDS[$to]);
            $grounded = LeadBrief::ground($value, is_string($item['frase'] ?? null) ? $item['frase'] : null, $customer);
            if ($grounded === null) {
                $discarded[] = $to; // no está en lo que escribió el cliente

                continue;
            }
            $fields[$to] = ['value' => $value, 'quote' => $grounded['quote'], 'index' => $grounded['index']];
        }

        $t = is_array($json['temperatura'] ?? null) ? $json['temperatura'] : [];
        $temperature = self::TEMPERATURE[(string) ($t['valor'] ?? '')] ?? null;
        if ($temperature !== null) {
            $quote = trim((string) ($t['frase'] ?? ''));
            $source = null;
            foreach (array_reverse($customer, true) as $i => $text) {
                if ($quote !== '' && LeadBrief::containsWords($text, $quote)) {
                    $source = $i;

                    break;
                }
            }
            $reason = trim((string) PiiScrubber::scrub(mb_substr(trim((string) ($t['razon'] ?? '')), 0, 160)));
            // La razón la escribe la IA: sus cifras deben estar en lo que dijo el cliente.
            $numbersOk = $this->numbers->validate($reason, [implode("\n", $customer)])->ok();
            if ($source !== null && $reason !== '' && $numbersOk) {
                $fields['temperature'] = ['value' => $temperature, 'quote' => mb_substr($quote, 0, LeadBrief::QUOTE_MAX), 'reason' => $reason, 'index' => $source];
            } else {
                $discarded[] = 'temperature';
            }
        }

        return ['purchase' => (bool) ($json['intencion_compra'] ?? false), 'fields' => $fields, 'discarded' => $discarded];
    }

    public static function system(): string
    {
        return 'Lees la conversación entre un cliente y un negocio de Latinoamérica (tienda o servicios) y llenas la ficha del cliente. '
            .'Usa solo lo que escribió el Cliente, nunca lo que dijo el Negocio. Copia cada valor con las mismas palabras del cliente; '
            .'si el cliente no lo dijo, deja el valor y la frase vacíos. No completes, no deduzcas, no traduzcas ni conviertas unidades. '
            .'necesidad: el producto o servicio que pide. cantidad: cuánto (unidades, metros, personas). ciudad: la ciudad que menciona. '
            .'zona: barrio, sector o zona. para_cuando: la fecha o el plazo. presupuesto: el monto o rango que menciona. '
            .'frase: el fragmento exacto del mensaje del cliente donde aparece el valor. '
            .'temperatura: caliente si quiere comprar o contratar pronto o pide precio, disponibilidad o cómo pagar; tibia si está comparando o pide información; '
            .'fria si no muestra interés o dice que más adelante; vacía si no se sabe. razon: una frase corta en español que la explique, sin nombres, correos ni teléfonos; '
            .'frase de la temperatura: el fragmento exacto del cliente que la respalda. '
            .'intencion_compra: true si el cliente quiere comprar o contratar algo.';
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $field = [
            'type' => 'object',
            'properties' => ['valor' => ['type' => 'string'], 'frase' => ['type' => 'string']],
            'required' => ['valor', 'frase'],
            'additionalProperties' => false,
        ];
        $fields = [];
        foreach (array_keys(self::KEYS) as $key) {
            $fields[$key] = $field;
        }

        return [
            'type' => 'object',
            'properties' => [
                'intencion_compra' => ['type' => 'boolean'],
                'campos' => ['type' => 'object', 'properties' => $fields, 'required' => array_keys(self::KEYS), 'additionalProperties' => false],
                'temperatura' => [
                    'type' => 'object',
                    'properties' => [
                        'valor' => ['type' => 'string', 'enum' => ['fria', 'tibia', 'caliente', '']],
                        'razon' => ['type' => 'string'],
                        'frase' => ['type' => 'string'],
                    ],
                    'required' => ['valor', 'razon', 'frase'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['intencion_compra', 'campos', 'temperatura'],
            'additionalProperties' => false,
        ];
    }
}
