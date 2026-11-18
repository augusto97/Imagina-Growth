<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Contracts;

use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Data\AiTool;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;

/**
 * Única puerta hacia el modelo (hub/CLAUDE.md §12). Elige el modelo y el esfuerzo
 * por función (`config/igr-ai.php`), aplica el tope de gasto del espacio de
 * trabajo, registra el costo en `ai_usage` y devuelve el texto o el JSON.
 *
 * Quien llama nunca habla con el SDK: si la IA no está disponible (sin clave,
 * apagada, sin presupuesto, error del proveedor) recibe `AiUnavailable` y usa su
 * plantilla sin IA. Las cifras de un texto narrativo se validan con
 * `NarrativeWriter` (inteligencia.md §6.1).
 */
interface AiGateway
{
    /** Si hay con qué llamar al modelo para ese espacio de trabajo (clave, interruptor y presupuesto). */
    public function available(string $workspaceId): bool;

    /**
     * Una llamada. Con `outputSchema` la respuesta es JSON validado por el API
     * (salidas estructuradas) y llega en `AiResult::$json`.
     *
     * @throws AiUnavailable
     */
    public function generate(AiRequest $request): AiResult;

    /**
     * Bucle de herramientas: el modelo llama a las herramientas (el hub las ejecuta
     * con su `handler`) hasta terminar o llegar a `maxSteps`. Cada llamada queda en
     * `AiResult::$toolCalls` con su entrada y su salida.
     *
     * @param  list<AiTool>  $tools
     *
     * @throws AiUnavailable
     */
    public function withTools(AiRequest $request, array $tools, int $maxSteps = 6): AiResult;
}
