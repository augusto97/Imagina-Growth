<?php

declare(strict_types=1);

namespace App\Domain\Flows\Actions;

use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Flows\Library\LibraryAdapter;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Support\InvalidFlow;
use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Crea un flujo de la biblioteca (modulos.md M09: «activar en 1 clic»). Antes
 * lo ajusta al espacio de trabajo (LibraryAdapter: sin WhatsApp conectado se
 * quitan sus pasos) e instala las plantillas de correo que falten desde la
 * biblioteca de M08. Con `activate`, si el borrador es válido, se publica y se
 * activa; si no (p. ej. falta elegir la plantilla aprobada de WhatsApp), queda
 * en borrador con los pendientes. Con `whatsapp = false` se crea sin los pasos de
 * WhatsApp aunque haya un número conectado (las estrategias lo piden así cuando
 * Meta todavía no aprobó las plantillas y el correo puede hacer el trabajo).
 */
final class CreateFlowFromLibrary
{
    /**
     * Flujos de la biblioteca que se excluyen entre sí: carrito y checkout abandonados, y la respuesta a un lead de
     * servicios (18) con las de su vertical que ya traen su propia respuesta (28 cursos y 29 propiedades).
     *
     * @var array<string, list<string>>
     */
    private const EXCLUSIVE = [
        'abandoned_cart' => ['abandoned_checkout'], 'abandoned_checkout' => ['abandoned_cart'],
        'service_lead' => ['enrollment_followup', 'property_interest'],
        'enrollment_followup' => ['service_lead'], 'property_interest' => ['service_lead'],
    ];

    public function __construct(
        private readonly SaveFlow $save,
        private readonly PublishFlow $publish,
        private readonly ChangeFlowStatus $status,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * @return array{flow: Flow, issues: array<string, list<string>>, activated: bool}
     *
     * @throws InvalidFlow
     */
    public function handle(string $key, bool $activate, ?string $userId = null, ?bool $whatsapp = null): array
    {
        $definition = FlowLibrary::find($key);
        if ($definition === null || $definition['graph'] === null) {
            throw new InvalidFlow(['key' => __('Ese flujo de la biblioteca no está disponible todavía.')]);
        }
        $workspace = $this->current->get();
        $definition = LibraryAdapter::forWorkspace($definition, $workspace->id, $whatsapp === false ? false : null);
        foreach ((array) $definition['graph']['nodes'] as $node) {
            $key = $node['config']['template_key'] ?? null;
            $channel = $node['type'] === 'send_email' ? 'email' : ($node['type'] === 'send_sms' ? 'sms' : ($node['type'] === 'notify_team' ? (string) ($node['config']['channel'] ?? 'email') : null));
            if (is_string($key) && $channel !== null && LibraryAdapter::installable($key) && TemplateRef::resolve($workspace->id, $channel, null, $key) === null) {
                TemplateLibrary::install($workspace, $key, null, $userId);
            }
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
        $this->excludeSiblings($flow);
        $activated = false;
        $issues = $result['issues'];
        if ($activate && $issues === []) {
            $this->publish->handle($flow, $userId);
            try {
                $this->status->handle($flow->refresh(), Flow::ACTIVE);
                $activated = true;
            } catch (InvalidFlow $e) {
                // Sin el canal conectado queda publicado pero sin activar, con el motivo a la vista.
                $issues = ['channel' => [$e->getMessage()]];
            }
        }

        return ['flow' => $flow->refresh(), 'issues' => $issues, 'activated' => $activated];
    }

    /**
     * Carrito y checkout abandonados se excluyen entre sí por defecto: quien deja el
     * checkout también deja el carrito, y no se le escribe dos veces por lo mismo.
     * Igual el lead de servicios con los de cursos y propiedades. Se cambia en la
     * configuración del flujo.
     */
    private function excludeSiblings(Flow $flow): void
    {
        $siblings = self::EXCLUSIVE[$flow->template_key] ?? [];
        if ($siblings === []) {
            return;
        }
        $others = Flow::query()->whereIn('template_key', $siblings)->where('status', '!=', Flow::ARCHIVED)->where('id', '!=', $flow->id)->get();
        foreach ($others as $other) {
            $flow->exclusions = array_values(array_unique([...(array) $flow->exclusions, $other->id]));
            $other->forceFill(['exclusions' => array_values(array_unique([...(array) $other->exclusions, $flow->id]))])->save();
        }
        $flow->save();
    }
}
