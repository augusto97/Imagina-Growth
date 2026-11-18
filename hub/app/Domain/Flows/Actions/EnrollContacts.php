<?php

declare(strict_types=1);

namespace App\Domain\Flows\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Jobs\AdvanceFlowRun;
use App\Domain\Flows\Models\Flow;
use Illuminate\Support\Str;

/**
 * Entrada manual, por API o por webhook entrante (modulos.md M09, «Disparadores»).
 * Respeta la reentrada, las exclusiones y el grupo de control.
 */
final class EnrollContacts
{
    public function __construct(private readonly EnterFlow $enter) {}

    /**
     * @param  list<string>  $contactIds  IDs internos
     * @param  array<string, mixed>  $props  datos sin datos personales para las plantillas
     * @return int cuántos entraron
     */
    public function handle(Flow $flow, array $contactIds, string $source = 'manual', array $props = []): int
    {
        $batch = (string) Str::ulid();
        $entered = 0;
        foreach (Contact::query()->whereKey(array_slice($contactIds, 0, 1000))->get() as $contact) {
            $run = $this->enter->handle($flow, $contact->id, $source.':'.$batch.':'.$contact->id, ['trigger' => $source, 'props' => $props]);
            if ($run !== null) {
                AdvanceFlowRun::dispatch($run->workspace_id, $run->id);
                $entered++;
            }
        }

        return $entered;
    }
}
