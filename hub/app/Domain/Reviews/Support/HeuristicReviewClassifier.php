<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Support;

use App\Domain\Reviews\Contracts\ReviewClassifier;
use Illuminate\Support\Str;

/**
 * Banderas de moderación por reglas, en español de Colombia, México y Venezuela.
 * No rechaza nada: solo manda a revisión. En `details` van las categorías que
 * saltaron (nunca el texto encontrado: puede ser un dato personal).
 */
final class HeuristicReviewClassifier implements ReviewClassifier
{
    /**
     * Palabras ofensivas (sin tildes, en minúsculas, comparadas por palabra
     * completa). Una queja dura sin groserías («estafa», «pésimo») no es ofensiva.
     */
    private const OFFENSIVE = [
        'hijueputa', 'hijuepucha', 'hpta', 'gonorrea', 'malparido', 'malparida', 'pirobo', 'piroba', 'carechimba',
        'puta', 'puto', 'putas', 'putos', 'mierda', 'mierdero', 'pendejo', 'pendeja', 'pendejos', 'marica', 'maricon',
        'culero', 'culera', 'pinche', 'chingada', 'chingado', 'chinga', 'verga', 'pendejada', 'mamaguevo', 'mamaguevazo',
        'coño', 'guevon', 'huevon', 'gueva', 'cabron', 'cabrona', 'zorra', 'imbecil', 'idiota', 'estupido', 'estupida',
    ];

    /** Frases típicas de publicidad o spam. */
    private const SPAM = ['gana dinero', 'ganar dinero', 'haz clic', 'click aqui', 'clic aqui', 'visita mi', 'sigueme', 'promo code', 'bitcoin', 'criptomoneda', 'casino', 'apuestas', 'onlyfans', 'telegram'];

    public function classify(?string $title, ?string $body, string $authorName = ''): array
    {
        $text = trim(($title ?? '').' '.($body ?? ''));
        $details = [];
        if ($text === '') {
            return ['flags' => [], 'details' => [], 'engine' => 'heuristic'];
        }
        $plain = Str::lower(Str::ascii($text));
        $words = preg_split('/[^a-z0-9ñ]+/u', Str::lower(Str::ascii(str_replace('ñ', 'n', $text)))) ?: [];

        $offensive = array_values(array_intersect(array_unique($words), array_map(fn ($w) => Str::ascii($w), self::OFFENSIVE)));
        if ($offensive !== []) {
            $details['offensive'] = ['words'];
        }

        $spam = [];
        // Enlaces (sin contar el dominio de un correo, que es un dato personal y se marca abajo).
        if (preg_match('#(https?://|www\.|(?<![@.\w-])[a-z0-9-]+\.(com|co|net|org|io|me|ly|shop|store)\b)#i', $text) === 1) {
            $spam[] = 'link';
        }
        foreach (self::SPAM as $phrase) {
            if (str_contains($plain, $phrase)) {
                $spam[] = 'phrase';
                break;
            }
        }
        if (preg_match('/(.)\1{7,}/u', $text) === 1) {
            $spam[] = 'repeated_chars';
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $text) ?? '';
        if (mb_strlen($letters) >= 20 && $letters === mb_strtoupper($letters)) {
            $spam[] = 'all_caps';
        }
        if ($spam !== []) {
            $details['spam'] = array_values(array_unique($spam));
        }

        $pii = [];
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text) === 1) {
            $pii[] = 'email';
        }
        // Teléfonos: 7 o más dígitos seguidos (con espacios, puntos o guiones) o con indicativo.
        if (preg_match('/(\+?\d[\d\s.\-]{8,}\d)/', $text, $m) === 1 && strlen(preg_replace('/\D/', '', $m[1]) ?? '') >= 7) {
            $pii[] = preg_match('/\b(cc|c\.c\.|cedula|cédula|nit|documento|ine|curp|rif)\b/iu', $text) === 1 ? 'document' : 'phone';
        }
        if (preg_match('/\b(calle|cl|carrera|cra|kr|avenida|av|diagonal|dg|transversal|tv)\.?\s*\d+[a-z]?\s*(#|no\.?|n°)\s*\d+/iu', $text) === 1) {
            $pii[] = 'address';
        }
        if ($pii !== []) {
            $details['personal_data'] = array_values(array_unique($pii));
        }

        return ['flags' => array_keys($details), 'details' => $details, 'engine' => 'heuristic'];
    }
}
