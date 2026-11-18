<?php

use App\Analytics\Ch;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Ingest\Events\EventIngested;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/*
 * Ayudas de las pruebas de leads (prefijo «leads»). Las cargan los archivos de
 * tests/Feature/Leads con require_once.
 */

function leadsSetUp($test): void
{
    $test->useIngestInfrastructure();
    $test->owner = $test->userWith2fa(['name' => 'Dueña', 'email' => 'duena@negocio.co']);
    $test->ws = $test->workspaceFor($test->owner, 'Asesorías Ruiz');
    [$test->site] = $test->connectedSite($test->ws, 'asesorias.co');
}

function leadsRun($test, Closure $fn): mixed
{
    return app(CurrentWorkspace::class)->run($test->ws, $fn);
}

/** @param array<string, mixed> $attrs */
function leadsContact($test, array $attrs = []): Contact
{
    $email = $attrs['email'] ?? 'ana@ruizabogados.co';

    return leadsRun($test, fn () => Contact::query()->create($attrs + [
        'workspace_id' => $test->ws->id,
        'email' => $email,
        'email_bidx' => BlindIndex::email($test->ws->id, $email),
        'first_name' => 'Ana',
        'last_name' => 'Ruiz',
        'city' => 'Bogotá',
        'country' => 'CO',
    ]));
}

/**
 * Publica un form.submitted en vivo (como lo haría la ingesta tras el commit del lote).
 *
 * @param  array<string, mixed>  $fields
 * @param  array<string, mixed>  $context
 */
function leadsForm($test, Contact $contact, array $fields = [], array $context = [], ?string $eventId = null): string
{
    $eventId ??= (string) Str::uuid7();
    $envelope = [
        'schema_version' => 4,
        'event_id' => $eventId,
        'event' => 'form.submitted',
        'occurred_at' => now()->toIso8601String(),
        'source' => ['type' => 'server', 'platform' => 'wordpress', 'site_id' => $test->site->publicId(), 'sdk' => 'connector/0.5.0'],
        'identity' => ['email' => $contact->email],
        'context' => $context + [
            'page' => ['path' => '/contacto/', 'url' => 'https://asesorias.co/contacto/'],
            'utm' => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'laboral'],
            'click_ids' => ['gclid' => 'Cj0KCQ-test'],
            'consent' => ['analytics' => true, 'marketing' => true],
        ],
        'properties' => [
            'form_plugin' => 'cf7', 'form_id' => '12', 'form_name' => 'Contacto', 'page_path' => '/contacto/',
            'fields' => $fields + ['email' => $contact->email, 'first_name' => 'Ana', 'service' => 'Asesoría laboral', 'message' => 'Necesito asesoría para un despido, es urgente'],
        ],
    ];
    event(new EventIngested($test->ws->id, $test->site->id, 'form.submitted', $eventId, $contact->id, $envelope));

    return $eventId;
}

/** @param array<string, mixed> $props */
function leadsBrowse($test, Contact $contact, string $event, string $path = '/', array $props = []): void
{
    $eventId = (string) Str::uuid7();
    $envelope = [
        'schema_version' => 4, 'event_id' => $eventId, 'event' => $event, 'occurred_at' => now()->toIso8601String(),
        'source' => ['type' => 'browser', 'platform' => 'wordpress', 'site_id' => $test->site->publicId(), 'sdk' => 'tracker/0.5.0'],
        'identity' => ['anonymous_id' => 'aid-1'],
        'context' => ['page' => ['path' => $path, 'url' => 'https://asesorias.co'.$path]],
        'properties' => $props,
    ];
    event(new EventIngested($test->ws->id, $test->site->id, $event, $eventId, $contact->id, $envelope));
}

/** @return Collection<int, Lead> */
function leadsAll($test): Collection
{
    return leadsRun($test, fn () => Lead::query()->orderBy('created_at')->get());
}

function leadsOne($test): Lead
{
    $lead = leadsAll($test)->last();
    expect($lead)->not->toBeNull();

    return $lead;
}

/** @return Collection<int, LeadActivity> */
function leadsActivities($test, Lead $lead): Collection
{
    return leadsRun($test, fn () => LeadActivity::query()->where('lead_id', $lead->id)->orderBy('id')->get());
}

/** @param array<string, mixed> $attrs */
function leadsSettings($test, array $attrs): LeadSettings
{
    return leadsRun($test, function () use ($test, $attrs) {
        $s = LeadSettings::for($test->ws->id);
        $s->fill($attrs + ['workspace_id' => $test->ws->id])->save();

        return $s;
    });
}

/** @return list<string> eventos del hub de un tipo en ClickHouse */
function leadsHubEvents($test, string $event): array
{
    return array_map(fn (array $r) => (string) $r['p'], $test->chRows($test->ws,
        'SELECT toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = {e:String} ORDER BY occurred_at',
        ['e' => $event]));
}

/**
 * Navegación previa escrita directo en ClickHouse (anónima, ligada al contacto por identity_map).
 *
 * @param  array<string, mixed>  $props
 */
function leadsChVisit($test, Contact $contact, string $event, string $path, string $at, array $props = []): void
{
    app(Ch::class)->insert('events', [[
        'workspace_id' => $test->ws->ch_id, 'site_id' => $test->site->ch_id, 'event_id' => (string) Str::uuid7(), 'event' => $event,
        'occurred_at' => CarbonImmutable::parse($at)->utc()->format('Y-m-d H:i:s.v'), 'received_at' => now()->utc()->format('Y-m-d H:i:s.v'),
        'source_type' => 'browser', 'anonymous_id' => 'aid-'.$contact->id, 'session_id' => 's-'.substr(md5($at), 0, 6), 'contact_id' => '', 'path' => $path,
        'channel' => 'paid_google', 'revenue' => '0', 'properties' => $props === [] ? new stdClass : $props,
    ]]);
    app(Ch::class)->insert('identity_map', [[
        'workspace_id' => $test->ws->ch_id, 'anonymous_id' => 'aid-'.$contact->id, 'contact_id' => $contact->id, 'linked_at' => now()->utc()->format('Y-m-d H:i:s.v'),
    ]]);
}

/**
 * Lead escrito directo (para hallazgos y tableros), con su contacto.
 *
 * @param  array<string, mixed>  $attrs
 */
function leadsRow($test, array $attrs = []): Lead
{
    $contact = leadsContact($test, ['email' => 'c'.Str::random(8).'@empresa.co']);
    $createdAt = $attrs['created_at'] ?? null;
    unset($attrs['created_at']);
    $lead = leadsRun($test, fn () => Lead::query()->create($attrs + [
        'workspace_id' => $test->ws->id, 'contact_id' => $contact->id, 'stage' => 'new', 'origin' => 'form', 'score' => 30, 'temperature' => 'warm',
        'score_reasons' => [], 'source' => ['channel' => 'paid_google'], 'last_activity_at' => now(),
    ]));
    if ($createdAt !== null) {
        leadsRun($test, fn () => Lead::query()->whereKey($lead->id)->update(['created_at' => $createdAt]));
    }

    return $lead->fresh() ?? $lead;
}

/** Varias vistas o envíos en ClickHouse de una ruta. */
function leadsChMany($test, string $event, string $path, int $n): void
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = [
            'workspace_id' => $test->ws->ch_id, 'site_id' => $test->site->ch_id, 'event_id' => (string) Str::uuid7(), 'event' => $event,
            'occurred_at' => now()->subDays(1 + $i % 20)->utc()->format('Y-m-d H:i:s.v'), 'received_at' => now()->utc()->format('Y-m-d H:i:s.v'),
            'source_type' => $event === 'form.submitted' ? 'server' : 'browser', 'anonymous_id' => 'a'.$i, 'session_id' => 's'.$i, 'contact_id' => '',
            'path' => $path, 'channel' => 'organic_search', 'revenue' => '0', 'properties' => new stdClass,
        ];
    }
    foreach (array_chunk($rows, 1000) as $chunk) {
        app(Ch::class)->insert('events', $chunk);
    }
}
