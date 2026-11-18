<?php

declare(strict_types=1);

namespace App\Domain\Flows\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Engine\FlowEngine;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowRunStep;
use App\Domain\Flows\Support\InvalidFlow;
use App\Domain\Workspaces\Models\User;
use Illuminate\Support\Str;

/**
 * Modo prueba (modulos.md M09): recorre el borrador (o la versión vigente) con
 * los datos de un contacto real, sin esperas, y los mensajes van a quien prueba
 * (su correo y el teléfono que escriba). No cuenta en la analítica, no emite
 * eventos ni crea cupones reales.
 */
final class StartTestRun
{
    public function __construct(private readonly EnterFlow $enter, private readonly FlowEngine $engine) {}

    /**
     * @return array{run: FlowRun, steps: list<array<string, mixed>>}
     *
     * @throws InvalidFlow
     */
    public function handle(Flow $flow, User $tester, ?Contact $contact, ?string $phone): array
    {
        $version = $flow->draftVersion() ?? $flow->currentVersion() ?? throw new InvalidFlow(['graph' => [__('El flujo no tiene pasos.')]]);
        $contact ??= Contact::query()->whereNull('merged_into_id')->orderByDesc('last_seen_at')->first();
        if ($contact === null) {
            throw new InvalidFlow(['contact_id' => [__('Necesitas al menos un contacto para probar con datos reales.')]]);
        }
        $run = $this->enter->handle($flow, $contact->id, 'test:'.Str::ulid(), [
            'trigger' => $flow->triggerEvent() ?? (string) ($flow->trigger['type'] ?? 'manual'),
            'props' => [],
            '_test' => array_filter(['email' => $tester->email, 'phone' => $phone !== null && $phone !== '' ? $phone : null, 'user_id' => $tester->id]),
        ], test: true, version: $version);
        if ($run === null) {
            throw new InvalidFlow(['graph' => [__('Conecta el disparador con el primer paso para probar.')]]);
        }
        $this->engine->advance($run->id);
        $steps = FlowRunStep::query()->where('run_id', $run->id)->orderBy('id')->get()
            ->map(fn (FlowRunStep $s) => ['node_id' => $s->node_id, 'outcome' => $s->outcome, 'details' => array_intersect_key((array) $s->details, array_flip(['branch', 'reason', 'channel', 'variant', 'result', 'message_id', 'code']))])
            ->all();

        return ['run' => $run->refresh(), 'steps' => array_values($steps)];
    }
}
