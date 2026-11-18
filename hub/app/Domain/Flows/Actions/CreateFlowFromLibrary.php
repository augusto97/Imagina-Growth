<?php

declare(strict_types=1);

namespace App\Domain\Flows\Actions;

use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Support\InvalidFlow;

/**
 * Crea un flujo de la biblioteca (modulos.md M09: «activar en 1 clic»). Con
 * `activate`, si el borrador es válido (están las plantillas), se publica y se
 * activa; si no, queda en borrador con los pendientes.
 */
final class CreateFlowFromLibrary
{
    public function __construct(
        private readonly SaveFlow $save,
        private readonly PublishFlow $publish,
        private readonly ChangeFlowStatus $status,
    ) {}

    /**
     * @return array{flow: Flow, issues: array<string, list<string>>, activated: bool}
     *
     * @throws InvalidFlow
     */
    public function handle(string $key, bool $activate, ?string $userId = null): array
    {
        $definition = FlowLibrary::find($key);
        if ($definition === null || $definition['graph'] === null) {
            throw new InvalidFlow(['key' => __('Ese flujo de la biblioteca no está disponible todavía.')]);
        }
        $result = $this->save->handle(null, [
            'name' => $definition['name'],
            'description' => $definition['description'],
            'template_key' => $definition['key'],
            'trigger' => $definition['trigger'],
            'goal' => $definition['goal'],
            'reentry_policy' => $definition['reentry_policy'],
            'graph' => $definition['graph'],
        ], $userId);
        $flow = $result['flow'];
        $activated = false;
        if ($activate && $result['issues'] === []) {
            $this->publish->handle($flow, $userId);
            $this->status->handle($flow->refresh(), Flow::ACTIVE);
            $activated = true;
        }

        return ['flow' => $flow->refresh(), 'issues' => $result['issues'], 'activated' => $activated];
    }
}
