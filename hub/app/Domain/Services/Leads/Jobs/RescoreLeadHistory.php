<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Jobs;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Support\LeadScorer;
use App\Domain\Services\Leads\Support\LeadWriter;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Segunda pasada de la navegación previa de un lead nuevo (M18, inteligencia.md §4.9).
 *
 * Quien mira la página de precios y llena el formulario en el mismo minuto: la visita
 * todavía viaja en el lote de la ingesta hacia ClickHouse cuando el formulario crea el
 * lead, y llegó anónima, así que ninguna señal en vivo la cuenta después. Esta pasada
 * corre un rato más tarde; cada señal suma una sola vez por lead, así que repetirla no
 * infla el puntaje. Si el lead pasa a «caliente», se avisa al vendedor como siempre.
 */
final class RescoreLeadHistory implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const DELAY_SECONDS = 90;

    public int $tries = 2;

    public function __construct(public string $workspaceIdValue, public string $leadId) {}

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(LeadScorer $scorer, LeadWriter $writer): void
    {
        $workspace = Workspace::query()->find($this->workspaceIdValue);
        $lead = Lead::query()->find($this->leadId);
        if ($workspace === null || $lead === null || ! $lead->isOpen()) {
            return;
        }
        $contact = Contact::query()->find($lead->contact_id);
        if ($contact === null) {
            return;
        }
        $settings = LeadSettings::for($workspace->id);
        $before = $lead->score;
        $scorer->history($lead, $contact, $settings, $workspace);
        if ($lead->isDirty('score_reasons') || $lead->score !== $before) {
            $writer->save($workspace, $lead, $settings);
        }
    }
}
