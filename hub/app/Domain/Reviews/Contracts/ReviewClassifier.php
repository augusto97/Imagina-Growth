<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Contracts;

/**
 * Filtro de moderación de las reseñas (M15). Devuelve las banderas que mandan la
 * reseña a la cola aunque la moderación sea automática:
 * `offensive` (lenguaje ofensivo), `spam` (enlaces, texto repetido, publicidad)
 * y `personal_data` (correos, teléfonos, documentos o direcciones en el texto).
 *
 * Hoy lo cumple `HeuristicReviewClassifier` (listas y expresiones regulares). En
 * la Fase 4 se registra aquí el `ReviewModerator` de IA (hub/CLAUDE.md §12), que
 * puede combinar sus banderas con las de las heurísticas; nada más cambia.
 */
interface ReviewClassifier
{
    /**
     * @return array{flags: list<string>, details: array<string, list<string>>, engine: string}
     */
    public function classify(?string $title, ?string $body, string $authorName = ''): array;
}
