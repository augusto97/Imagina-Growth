<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Reactivation;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Support\NarrativeWriter;
use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Services\Leads\Assistant\LeadMessageWriter;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * «Despierta la base dormida» (auditoría §5.1 función 7, nodo de flujo «Reactivar con
 * IA»): un mensaje para retomar con cada contacto según lo último que habló (su última
 * conversación) y su historia de compras. Función `reactivation`, económica.
 *
 * **La IA no inventa números ni datos:** recibe solo hechos ya calculados, sin correos
 * ni teléfonos (PiiScrubber); toda cifra debe estar en ellos (NarrativeWriter, una
 * corrección y luego el texto sin IA); un enlace que no esté se quita, y un descuento,
 * cupón o «gratis» que el dueño no puso en `oferta` manda al texto sin IA. Para una
 * plantilla de WhatsApp el texto va en una sola línea, porque es el valor de una
 * variable y Meta no acepta saltos de línea ni tabulaciones en los parámetros.
 */
final class ReactivationWriter
{
    public const FEATURE = 'reactivation';

    public const MAX_CHARS = 500;

    /** Largo máximo cuando el texto es la variable de una plantilla (el cuerpo aprobado suma el resto, hasta 1.024). */
    public const TEMPLATE_MAX = 400;

    private const MESSAGES = 12;

    private const PROMISE = '/\b(descuentos?|cup[oó]n(es)?|gratis|promoci[oó]n(es)?|rebaja|regalo|2x1|oferta especial)\b|%/iu';

    public function __construct(private readonly NarrativeWriter $writer) {}

    /**
     * @param  'text'|'template'|'email'  $mode
     * @return array{text: string, summary: string|null, source: 'ai'|'fallback', reason: string|null}
     */
    public function write(Workspace $workspace, Contact $contact, string $mode, ?string $offer = null, int $lookbackDays = 365): array
    {
        $facts = self::facts($workspace, $contact, $mode, $offer, $lookbackDays);
        $request = AiRequest::ask($workspace->id, self::FEATURE, self::system(), "Hechos (JSON):\n".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), self::schema());
        $result = $this->writer->write($request, $facts, fn (): string => self::fallback($facts));
        $text = $result->json !== null ? trim((string) ($result->json['mensaje'] ?? '')) : trim($result->text);
        $summary = $result->json !== null && is_string($result->json['resumen'] ?? null) && trim($result->json['resumen']) !== '' ? trim($result->json['resumen']) : null;
        $text = LeadMessageWriter::onlyKnownLinks($text, $facts);
        $reason = isset($result->validation['fallback_reason']) ? (string) $result->validation['fallback_reason'] : null;
        $fromAi = $result->fromAi();
        if ($fromAi && ($text === '' || mb_strlen($text) > self::MAX_CHARS * 2 || ($offer === null && preg_match(self::PROMISE, $text) === 1))) {
            [$text, $summary, $fromAi, $reason] = [self::fallback($facts), null, false, 'promise'];
        }
        if ($mode === 'template') {
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        }
        $max = $mode === 'template' ? self::TEMPLATE_MAX : self::MAX_CHARS;

        return ['text' => mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text, 'summary' => $summary, 'source' => $fromAi ? 'ai' : 'fallback', 'reason' => $reason];
    }

    /**
     * Los hechos del contacto, sin datos personales.
     *
     * @return array<string, mixed>
     */
    public static function facts(Workspace $workspace, Contact $contact, string $mode, ?string $offer, int $lookbackDays): array
    {
        $now = CarbonImmutable::now();
        $first = is_string($contact->first_name) ? trim(explode(' ', trim($contact->first_name))[0]) : '';
        $conversation = Conversation::query()->where('contact_id', $contact->id)->whereNotNull('last_message_at')
            ->where('last_message_at', '>=', $now->subDays(max(1, $lookbackDays)))->orderByDesc('last_message_at')->first();
        $messages = [];
        if ($conversation !== null) {
            $rows = Message::query()->where('conversation_id', $conversation->id)->orderByDesc('created_at')->orderByDesc('id')->limit(self::MESSAGES)->get()->reverse();
            foreach ($rows as $m) {
                $text = trim((string) (((array) $m->payload)['text'] ?? ''));
                if ($text !== '') {
                    $messages[] = ['de' => $m->direction === 'in' ? 'cliente' : 'negocio', 'texto' => PiiScrubber::scrub(mb_substr($text, 0, 300))];
                }
            }
        }
        $last = $contact->getAttribute('last_order_at');
        $lastOrder = Order::query()->where('contact_id', $contact->id)->where('is_paid', true)->orderByDesc('created_at')->first();
        $products = $lastOrder !== null ? OrderItem::query()->where('order_id', $lastOrder->id)->limit(5)->pluck('name')->map(fn ($n) => mb_substr((string) $n, 0, 80))->values()->all() : [];
        $orders = (int) $contact->getAttribute('orders_count');

        return array_filter([
            'negocio' => $workspace->name,
            'sitio' => Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->value('url'),
            'cliente' => $first !== '' ? $first : null,
            'canal' => $mode === 'email' ? 'correo' : 'whatsapp',
            'ultima_conversacion' => $messages !== [] && $conversation?->last_message_at !== null ? [
                'hace_dias' => (int) floor($conversation->last_message_at->diffInDays($now, true)),
                'mensajes' => $messages,
            ] : null,
            'compras' => $orders > 0 ? array_filter([
                'pedidos' => $orders,
                'dias_desde_la_ultima' => $last instanceof CarbonInterface ? (int) floor($last->diffInDays($now, true)) : null,
                'productos_de_la_ultima' => $products !== [] ? $products : null,
            ], fn ($v) => $v !== null) : null,
            'oferta' => $offer !== null && trim($offer) !== '' ? mb_substr(trim($offer), 0, 200) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public static function system(): string
    {
        return 'Escribes, en nombre de un negocio de Latinoamérica, un mensaje para retomar el contacto con un cliente que hace tiempo no escribe ni compra. '
            .'Español neutro, cercano y breve (máximo 350 caracteres), tuteando, en un solo párrafo, sin firmar con nombres de personas y con un emoji como máximo. '
            .'Personalízalo con lo último que habló («ultima_conversacion»: retoma ese tema de forma natural, sin copiar sus frases) y con su historia de compras («compras»: lo que compró, si sirve). '
            .'Si no hay conversación ni compras, escribe un saludo corto para retomar el contacto. Termina con una pregunta fácil de responder. '
            .'No inventes precios, descuentos, cupones, regalos, envíos gratis, stock, fechas ni plazos: si en los hechos no hay «oferta», no ofrezcas nada; si la hay, úsala tal cual. '
            .'Usa solo cifras que estén en los hechos. No pongas enlaces que no estén en los hechos. No menciones que hace tiempo no compra ni cuánto gastó. No hables de temas ajenos al negocio. '
            .'Devuelve en «resumen» una frase de qué habló la última vez (vacío si no hay conversación) y en «mensaje» el texto para el cliente.';
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['resumen' => ['type' => 'string'], 'mensaje' => ['type' => 'string']],
            'required' => ['resumen', 'mensaje'],
            'additionalProperties' => false,
        ];
    }

    /**
     * El mensaje sin IA, con los mismos hechos y sin cifras.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function fallback(array $facts): string
    {
        $name = is_string($facts['cliente'] ?? null) ? ' '.$facts['cliente'] : '';
        $product = $facts['compras']['productos_de_la_ultima'][0] ?? null;
        $about = is_string($product) ? (string) __(' para lo que necesites después de tu :product', ['product' => $product]) : '';
        $offer = is_string($facts['oferta'] ?? null) ? ' '.$facts['oferta'].'.' : '';

        return (string) __('Hola:name, te escribimos de :business. Queremos saber cómo te ha ido y si te podemos ayudar:about.:offer ¿Te cuento las novedades?', [
            'name' => $name, 'business' => (string) ($facts['negocio'] ?? ''), 'about' => $about, 'offer' => $offer,
        ]);
    }
}
