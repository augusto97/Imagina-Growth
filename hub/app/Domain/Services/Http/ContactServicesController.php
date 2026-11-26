<?php

declare(strict_types=1);

namespace App\Domain\Services\Http;

use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Lo de servicios de una persona (modulos.md M03 y M18), para su perfil y la ficha del lead: sus leads con la etapa en
 * palabras, sus citas (las próximas primero) y sus cotizaciones, con los totales que muestra el encabezado (valor ganado,
 * citas cumplidas, no asistidas y próximas). `GET services/contacts/{contact}`, permiso `useLeads`; el rol agente ve
 * solo los leads que tiene asignados, como en Leads.
 */
final class ContactServicesController
{
    /** Filas de cada lista (el perfil muestra lo reciente; el resto está en Leads y la Agenda). */
    public const LIMIT = 20;

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function __invoke(string $contact): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $person = ContactAccess::find($contact);
        $workspace = $this->current->get();

        $leads = Lead::query()->where('contact_id', $person->id)
            ->when(! Gate::allows('ws.viewAllContacts'), fn ($q) => $q->where('owner_id', (string) auth()->id()))
            ->orderByDesc('created_at')->limit(self::LIMIT)->get();
        $pipelines = LeadPipeline::query()->whereIn('id', $leads->pluck('pipeline_id')->filter()->unique()->values()->all())->get()->keyBy('id');
        $default = $leads->contains(fn (Lead $l) => $l->pipeline_id === null || ! $pipelines->has($l->pipeline_id)) ? Pipelines::default($workspace->id) : null;

        $now = now();
        $upcoming = Appointment::query()->where('contact_id', $person->id)->where('starts_at', '>=', $now)->orderBy('starts_at')->limit(self::LIMIT)->get();
        $past = Appointment::query()->where('contact_id', $person->id)->where('starts_at', '<', $now)->orderByDesc('starts_at')->limit(self::LIMIT)->get();
        $appointments = $upcoming->concat($past);
        $services = BookingService::query()->whereIn('id', $appointments->pluck('service_id')->unique()->values()->all())->pluck('name', 'id');
        $staff = BookingStaff::query()->whereIn('id', $appointments->pluck('staff_id')->filter()->unique()->values()->all())->pluck('name', 'id');
        $counts = Appointment::query()->where('contact_id', $person->id)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        $quotes = Quote::query()->where('contact_id', $person->id)->orderByDesc('created_at')->limit(self::LIMIT)->get();
        $calculators = QuoteCalculator::query()->whereIn('id', $quotes->pluck('calculator_id')->unique()->values()->all())->pluck('name', 'id');

        return response()->json([
            'currency' => $workspace->currency,
            'leads' => $leads->map(function (Lead $l) use ($pipelines, $default): array {
                $pipeline = $pipelines->get((string) $l->pipeline_id) ?? $default;
                $stage = $pipeline?->stage($l->stage);

                return [
                    'id' => $l->publicId(),
                    'stage' => $l->stage,
                    'stage_label' => is_array($stage) ? (string) $stage['label'] : $l->stage,
                    'stage_kind' => $pipeline?->kindOf($l->stage) ?? 'open',
                    'temperature' => $l->temperature,
                    'score' => (int) $l->score,
                    'service' => $l->service,
                    'value' => $l->value_ws,
                    'origin' => $l->origin,
                    'next_step' => $l->next_step,
                    'next_step_at' => $l->next_step_at?->toIso8601String(),
                    'first_response_at' => $l->first_response_at?->toIso8601String(),
                    'created_at' => $l->created_at?->toIso8601String(),
                    'won_at' => $l->won_at?->toIso8601String(),
                    'lost_at' => $l->lost_at?->toIso8601String(),
                    'lost_reason' => $l->lost_reason,
                ];
            })->values(),
            'appointments' => $appointments->map(fn (Appointment $a) => [
                'id' => $a->publicId(),
                'status' => $a->status,
                'starts_at' => $a->starts_at->toIso8601ZuluString(),
                'ends_at' => $a->ends_at->toIso8601ZuluString(),
                'service' => isset($services[$a->service_id]) ? (string) $services[$a->service_id] : null,
                'staff' => $a->staff_id !== null && isset($staff[$a->staff_id]) ? (string) $staff[$a->staff_id] : null,
                'channel' => $a->channel,
            ])->values(),
            'quotes' => $quotes->map(fn (Quote $q) => [
                'id' => $q->publicId(),
                'created_at' => $q->created_at->toIso8601ZuluString(),
                'calculator' => isset($calculators[$q->calculator_id]) ? (string) $calculators[$q->calculator_id] : null,
                'estimate_min' => $q->estimate_min !== null ? (string) $q->estimate_min : null,
                'estimate_max' => $q->estimate_max !== null ? (string) $q->estimate_max : null,
                'currency' => $q->currency,
                'lead_id' => $q->lead_id !== null ? PrefixedId::encode('ld', $q->lead_id) : null,
            ])->values(),
            'totals' => [
                'won_value' => number_format((float) Lead::query()->where('contact_id', $person->id)->whereNotNull('won_at')->sum('value_ws'), 2, '.', ''),
                'appointments_completed' => (int) ($counts['completed'] ?? 0),
                'appointments_no_show' => (int) ($counts['no_show'] ?? 0),
                'appointments_upcoming' => $upcoming->whereIn('status', Appointment::ACTIVE)->count(),
                'quotes' => Quote::query()->where('contact_id', $person->id)->count(),
            ],
        ]);
    }
}
