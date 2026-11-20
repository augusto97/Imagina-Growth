<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Assistant;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Support\NarrativeWriter;
use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use App\Domain\Messaging\Models\Message;
use App\Domain\Services\Leads\Brief\LeadBrief;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * El mensaje que la IA le propone al vendedor (M18): el sugerido de «Hoy» y cada
 * toque del seguimiento automático (función `lead_followup`, económica).
 *
 * Escribe con los datos del lead (qué pidió, cantidad, ciudad, para cuándo, la etapa
 * y el último mensaje del cliente) para que el mensaje aporte algo, nunca un «¿pudiste
 * ver la propuesta?». **No inventa:** toda cifra debe estar en los hechos
 * (`NarrativeWriter`, una corrección y luego el texto sin IA), y un enlace que no
 * venga en los hechos se quita. Sin IA, arma un texto corto con los mismos hechos.
 */
final class LeadMessageWriter
{
    public const FEATURE = 'lead_followup';

    public const MAX_CHARS = 600;

    /** Situaciones: lo que el vendedor tiene que hacer con este lead. */
    public const SITUATIONS = ['reply', 'first_contact', 'next_step', 'stale', 'task', 'followup'];

    public function __construct(private readonly NarrativeWriter $writer) {}

    /**
     * @param  array{task?: string|null, step?: int|null, channel?: string|null}  $extra
     * @return array{text: string, source: 'ai'|'fallback', reason: string|null}
     */
    public function write(Workspace $workspace, Lead $lead, string $situation, array $extra = []): array
    {
        $facts = self::facts($workspace, $lead, $situation, $extra);
        $request = AiRequest::ask($workspace->id, self::FEATURE, self::system(), "Hechos (JSON):\n".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), self::schema());
        $fallback = fn (): string => self::fallback($facts);
        $result = $this->writer->write($request, $facts, $fallback, fn ($r) => (string) ($r->json['mensaje'] ?? $r->text));
        $text = self::onlyKnownLinks(trim($result->text), $facts);
        if ($text === '' || mb_strlen($text) > self::MAX_CHARS * 2) {
            return ['text' => self::fallback($facts), 'source' => 'fallback', 'reason' => 'invalid'];
        }

        return [
            'text' => mb_substr($text, 0, self::MAX_CHARS),
            'source' => $result->fromAi() ? 'ai' : 'fallback',
            'reason' => isset($result->validation['fallback_reason']) ? (string) $result->validation['fallback_reason'] : null,
        ];
    }

    /**
     * Los hechos que puede usar el modelo, sin correos ni teléfonos.
     *
     * @param  array{task?: string|null, step?: int|null, channel?: string|null}  $extra
     * @return array<string, mixed>
     */
    public static function facts(Workspace $workspace, Lead $lead, string $situation, array $extra = []): array
    {
        $contact = Contact::query()->find($lead->contact_id);
        $pipeline = Pipelines::for($lead);
        $since = $lead->stage_changed_at ?? $lead->created_at;
        $last = Message::query()->where('contact_id', $lead->contact_id)->where('direction', 'in')->latest('created_at')->first();
        $lastText = $last !== null ? trim((string) (((array) $last->payload)['text'] ?? '')) : '';
        $brief = LeadBrief::facts($lead->brief);
        unset($brief['temperature']);
        $firstName = $contact !== null && is_string($contact->first_name) && trim($contact->first_name) !== '' ? trim(explode(' ', trim($contact->first_name))[0]) : null;

        return array_filter([
            'negocio' => $workspace->name,
            'sitio' => Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->value('url'),
            'cliente' => $firstName,
            'situacion' => in_array($situation, self::SITUATIONS, true) ? $situation : 'followup',
            'canal' => $extra['channel'] ?? null,
            'toque' => $extra['step'] ?? null,
            'etapa' => $pipeline->stage($lead->stage)['label'] ?? $lead->stage,
            'dias_en_la_etapa' => $since !== null ? (int) floor($since->diffInDays(CarbonImmutable::now(), true)) : null,
            'servicio' => $lead->service,
            'ficha' => $brief !== [] ? $brief : null,
            'ciudad_del_contacto' => $contact !== null && is_string($contact->getAttribute('city')) ? $contact->getAttribute('city') : null,
            'proximo_paso' => $lead->next_step,
            'tarea' => $extra['task'] ?? null,
            'ultimo_mensaje_del_cliente' => $lastText !== '' ? PiiScrubber::scrub(mb_substr($lastText, 0, 500)) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public static function system(): string
    {
        return 'Escribes, en nombre de un negocio de Latinoamérica, el mensaje que su vendedor le enviará a un cliente por WhatsApp o correo. '
            .'Español neutro, cercano y breve (máximo 450 caracteres), tuteando, sin firmar con nombres de personas y con un solo emoji como máximo. '
            .'Usa los hechos: qué pidió el cliente, la cantidad, la ciudad o zona, para cuándo lo necesita, la etapa y su último mensaje. '
            .'El mensaje debe aportar algo concreto: una opción, un dato útil sobre lo que pidió, una pregunta específica para avanzar o una propuesta de siguiente paso. '
            .'Nunca escribas mensajes vacíos como «¿pudiste ver la propuesta?», «¿qué has pensado?» o «solo quería saber». '
            .'situacion: reply = el cliente escribió y espera respuesta (respóndele sobre lo que dijo, sin inventar datos que no tienes; si falta información, dilo y ofrece confirmarla); '
            .'first_contact = primer contacto con un cliente interesado; next_step = toca el próximo paso acordado; stale = lleva días sin avanzar; task = hay una tarea pendiente con él; '
            .'followup = seguimiento automático número «toque» (1 a 4; en el último, despídete con amabilidad y deja la puerta abierta). '
            .'No inventes precios, descuentos, stock, fechas, plazos ni cifras: usa solo las cifras que estén en los hechos. No pongas enlaces que no estén en los hechos. '
            .'No hables de temas ajenos al negocio. Devuelve solo el mensaje en «mensaje».';
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['mensaje' => ['type' => 'string']],
            'required' => ['mensaje'],
            'additionalProperties' => false,
        ];
    }

    /**
     * El mensaje sin IA, con los mismos hechos.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function fallback(array $facts): string
    {
        $name = is_string($facts['cliente'] ?? null) ? ' '.$facts['cliente'] : '';
        $need = $facts['ficha']['need'] ?? $facts['servicio'] ?? null;
        $city = $facts['ficha']['city'] ?? null;
        $about = is_string($need) ? __(' por :need', ['need' => $need]).(is_string($city) ? __(' en :city', ['city' => $city]) : '') : '';
        $business = (string) ($facts['negocio'] ?? '');

        return match ($facts['situacion'] ?? 'followup') {
            'reply' => __('Hola:name, gracias por escribirnos. Ya reviso lo que nos contaste y te respondo enseguida.', ['name' => $name]),
            'first_contact' => __('Hola:name, te escribo de :business por tu solicitud:about. ¿Te queda bien que te cuente las opciones por aquí?', ['name' => $name, 'business' => $business, 'about' => $about]),
            'stale', 'next_step', 'task' => __('Hola:name, te escribo de :business para seguir con tu solicitud:about. ¿Quieres que avancemos con el siguiente paso?', ['name' => $name, 'business' => $business, 'about' => $about]),
            default => ((int) ($facts['toque'] ?? 1)) >= 4
                ? __('Hola:name, no queremos insistir. Si más adelante necesitas ayuda:about, aquí estamos en :business.', ['name' => $name, 'about' => $about, 'business' => $business])
                : __('Hola:name, te escribo de :business por tu solicitud:about. ¿Te ayudo a resolver alguna duda para avanzar?', ['name' => $name, 'business' => $business, 'about' => $about]),
        };
    }

    /**
     * Quita los enlaces que no vienen en los hechos (la IA no inventa direcciones).
     *
     * @param  array<string, mixed>  $facts
     */
    public static function onlyKnownLinks(string $text, array $facts): string
    {
        $known = [];
        array_walk_recursive($facts, function (mixed $v) use (&$known): void {
            if (is_string($v) && preg_match_all('#https?://\S+#iu', $v, $m) > 0) {
                array_push($known, ...$m[0]);
            }
        });
        $clean = (string) preg_replace_callback('#https?://[^\s)»"]+#iu', fn (array $m) => in_array(rtrim($m[0], '.,;!?'), $known, true) ? $m[0] : '', $text);

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $clean));
    }
}
