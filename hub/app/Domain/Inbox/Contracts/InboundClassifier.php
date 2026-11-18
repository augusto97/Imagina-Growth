<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Contracts;

use App\Domain\Inbox\Data\InboundClassification;

/**
 * Clasificador de mensajes entrantes (M11, inteligencia.md §6.6): intención
 * (compra, soporte, queja, baja u otra) y sentimiento. **Una baja revoca el
 * consentimiento de inmediato**: las palabras clave ya lo hacen en
 * `InboundHandler`; el clasificador de IA cubre las variantes («ya no me manden
 * nada», «borren mi número»). Por defecto `HeuristicInboundClassifier`.
 */
interface InboundClassifier
{
    public function classify(string $workspaceId, string $text): InboundClassification;
}
