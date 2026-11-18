<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot\Support;

/**
 * Reglas de Meta para el cuerpo de una plantilla de WhatsApp que se revisan antes
 * de proponerla **[verificar]** contra la guía vigente de Meta:
 * hasta 1.024 caracteres; variables `{{1}}`, `{{2}}`… seguidas desde 1; el texto no
 * empieza ni termina con una variable; dos variables no van pegadas; al menos
 * tres palabras por variable; sin acortadores de enlaces.
 */
final class WhatsAppTemplateRules
{
    public const MAX_LENGTH = 1024;

    /** @return list<string> problemas (vacío si cumple) */
    public static function check(string $body): array
    {
        $problems = [];
        $body = trim($body);
        if ($body === '') {
            return [__('El texto está vacío.')];
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            $problems[] = __('El texto pasa de :n caracteres.', ['n' => self::MAX_LENGTH]);
        }
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m);
        $vars = array_map('intval', $m[1]);
        $unique = array_values(array_unique($vars));
        sort($unique);
        if ($unique !== [] && $unique !== range(1, count($unique))) {
            $problems[] = __('Las variables deben ir seguidas desde {{1}}.');
        }
        if (preg_match('/\{\{(?!\s*\d+\s*\}\})/', $body) === 1) {
            $problems[] = __('Usa solo variables numeradas, como {{1}}.');
        }
        if (preg_match('/^\{\{\s*\d+\s*\}\}/', $body) === 1 || preg_match('/\{\{\s*\d+\s*\}\}[.!?¡¿\s]*$/u', $body) === 1) {
            $problems[] = __('El texto no puede empezar ni terminar con una variable.');
        }
        if (preg_match('/\}\}\s*\{\{/', $body) === 1) {
            $problems[] = __('Dos variables no pueden ir pegadas.');
        }
        $words = count(preg_split('/\s+/u', (string) preg_replace('/\{\{\s*\d+\s*\}\}/', ' ', $body), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($vars !== [] && $words < 3 * count($vars)) {
            $problems[] = __('Hay demasiadas variables para lo corto del texto.');
        }
        if (preg_match('/\b(bit\.ly|tinyurl\.com|goo\.gl|t\.co|ow\.ly|cutt\.ly)\b/i', $body) === 1) {
            $problems[] = __('Meta rechaza los acortadores de enlaces.');
        }

        return $problems;
    }
}
