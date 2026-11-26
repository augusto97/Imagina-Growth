<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Listeners;

use App\Domain\Consent\Support\ConsentMode;
use App\Domain\Measurement\Conversions\ConversionInputFactory;
use App\Domain\Measurement\Conversions\TargetRegistry;
use App\Domain\Measurement\Jobs\DeliverConversion;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;

/**
 * ConversionRouter (hub/CLAUDE.md §11): por cada evento en vivo que tenga
 * equivalente en las plataformas, arma el payload de cada destino activo y lo
 * encola en conversions:{plataforma}.
 *
 * Reglas:
 *  - Nunca eventos importados ni del hub.
 *  - Consentimiento (cumplimiento.md §3.3): en modo estricto exige
 *    context.consent.marketing === true; en modo informado basta con que no
 *    sea false.
 *  - El event_id de la plataforma es el event_id de Imagina Growth, el mismo
 *    que usa el píxel del navegador: así se deduplica.
 *  - Un mismo evento no se encola dos veces para el mismo destino y endpoint
 *    aunque la ingesta lo reprocese.
 *  - `cta.clicked` (clics a WhatsApp, llamada o correo) sale como `Contact`
 *    solo si el destino lo encendió (apagado por defecto) y solo esos tipos.
 */
final class ConversionRouter
{
    public function __construct(
        private readonly TargetRegistry $targets,
        private readonly ConversionInputFactory $inputs,
        private readonly CurrentWorkspace $current,
    ) {}

    public function handle(EventIngested $event): void
    {
        $map = config('igr-measurement.events')[$event->event] ?? null;
        if (! is_array($map)) {
            return;
        }
        // Un evento con `kinds` (los clics que valen) solo sale para esos tipos: WhatsApp, llamada o correo.
        if (isset($map['kinds']) && ! in_array($event->envelope['properties']['kind'] ?? null, (array) $map['kinds'], true)) {
            return;
        }
        $sourceType = $event->envelope['source']['type'] ?? null;
        if (! in_array($sourceType, ['browser', 'server'], true)) {
            return;
        }
        $workspace = Workspace::query()->find($event->workspaceId);
        if ($workspace === null) {
            return;
        }

        $this->current->run($workspace, function () use ($workspace, $event): void {
            $destinations = AdDestination::query()->where('status', 'active')->get()
                ->filter(fn (AdDestination $d) => $d->sends($event->event));
            if ($destinations->isEmpty() || ! $this->consentAllows($workspace, $event)) {
                return;
            }
            $site = $event->siteId !== '' ? Site::query()->find($event->siteId) : null;
            $input = $this->inputs->make($event, $workspace, $site);

            foreach ($destinations as $destination) {
                foreach ($this->targets->all() as $target) {
                    if (! $target->supports($destination, $event->event)) {
                        continue;
                    }
                    $guard = RedisKey::for($workspace->id, "conv:{$destination->id}:{$target->key()}:{$event->eventId}");
                    if (! Cache::add($guard, 1, now()->addDays(2))) {
                        continue;
                    }
                    $payload = $target->build($input, $destination);
                    DeliverConversion::dispatch($workspace->id, $destination->id, $target->key(), $payload, [
                        'source_event_id' => $event->eventId,
                        'source_event' => $event->event,
                        'conversion' => (string) (config('igr-measurement.events')[$event->event][$target->platform()->value] ?? $event->event),
                        'contact_id' => $event->contactId,
                        'match_keys' => $target->matchKeys($payload),
                        'first_attempt_at' => time(),
                        'attempt' => 1,
                    ])->onQueue($target->platform()->queue());
                }
            }
        });
    }

    private function consentAllows(Workspace $workspace, EventIngested $event): bool
    {
        $consent = $event->envelope['context']['consent'] ?? null;

        return ConsentMode::allowsMarketing($workspace, is_array($consent) ? $consent : null);
    }
}
