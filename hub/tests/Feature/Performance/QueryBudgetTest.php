<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Workspaces\Enums\AccountType;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Support\MessagingFixtures;

/*
 * Presupuesto de consultas de las pantallas que se cargan más (docs/rendimiento.md): la cantidad de consultas de
 * MariaDB no crece con la cantidad de filas. Antes, la bandeja hacía una consulta por conversación (sin índice: 4 s
 * con 300 conversaciones) y /api/me cinco por cada espacio de trabajo de una agencia.
 */

uses(MessagingFixtures::class);

/** Consultas de MariaDB que hace `$fn`. */
function queryCount(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $fn();

        return count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }
}

/**
 * Conversaciones de WhatsApp con su historial: tres mensajes cada una, el último a `$last` menos `$i` minutos.
 *
 * @return list<array{conversation: string, last_text: string}>
 */
function seedInbox($test, int $from, int $count, CarbonImmutable $last): array
{
    return app(CurrentWorkspace::class)->run($test->ws, function () use ($test, $from, $count, $last): array {
        $out = [];
        for ($i = $from; $i < $from + $count; $i++) {
            $contact = Contact::query()->create(['workspace_id' => $test->ws->id, 'first_name' => 'Cliente '.$i, 'country' => 'CO']);
            $at = $last->subMinutes($i);
            $conversation = Conversation::query()->create([
                'workspace_id' => $test->ws->id, 'contact_id' => $contact->id, 'channel_account_id' => $test->wa->id, 'status' => 'open',
                'last_message_at' => $at, 'last_inbound_at' => $at, 'window_expires_at' => $at->addDay(),
            ]);
            $rows = [];
            foreach ([[$at->subMinutes(10), 'in', 'Hola '.$i], [$at, 'out', 'Respuesta vieja '.$i], [$at, 'out', 'Último '.$i]] as [$when, $direction, $text]) {
                $rows[] = [
                    'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $test->ws->id, 'contact_id' => $contact->id, 'channel_account_id' => $test->wa->id,
                    'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'direction' => $direction, 'kind' => 'service', 'source_type' => 'inbox',
                    'status' => $direction === 'in' ? 'received' : 'sent', 'payload' => Crypt::encryptString((string) json_encode(['text' => $text])),
                    'conversation_id' => $conversation->id, 'created_at' => $when, 'updated_at' => $when,
                ];
                usleep(1000); // ULID creciente: con la misma fecha gana el id mayor («Último»).
            }
            DB::table('messages')->insert($rows);
            $out[] = ['conversation' => $conversation->publicId(), 'last_text' => 'Último '.$i];
        }

        return $out;
    });
}

it('la bandeja trae el último mensaje de cada conversación con las mismas consultas para 2 o 12 conversaciones', function () {
    $this->setUpMessaging();
    $now = CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC');
    $api = "/api/w/{$this->ws->publicId()}/inbox/conversations?status=open";

    $first = seedInbox($this, 0, 2, $now);
    $few = queryCount(fn () => $this->actingAs($this->owner)->getJson($api)->assertOk()->assertJsonCount(2, 'data'));

    $all = array_merge($first, seedInbox($this, 2, 10, $now));
    $res = null;
    $many = queryCount(function () use ($api, &$res) {
        $res = $this->actingAs($this->owner)->getJson($api)->assertOk()->assertJsonCount(12, 'data');
    });

    // Igual o menos (el canal de la cuenta de WhatsApp queda en memoria de una petición a otra en la prueba).
    expect($many)->toBeLessThanOrEqual($few);
    $previews = collect($res->json('data'))->mapWithKeys(fn (array $c) => [$c['id'] => $c['preview']['text'] ?? null])->all();
    foreach ($all as $c) {
        expect($previews[$c['conversation']])->toBe($c['last_text']);
    }
    expect($res->json('data.0.preview.direction'))->toBe('out');
});

/** `$n` clientes más en la cuenta de la agencia (la persona entra por la cuenta, no es miembro directo). */
function addAgencyClients($test, Account $agency, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $client = $test->workspaceFor($test->userWith2fa(), 'Cliente '.Str::random(5));
        $client->forceFill(['account_id' => $agency->id])->save();
    }
}

it('/api/me resuelve roles, facturación y marca de todos los espacios con las mismas consultas para 3 o 12', function () {
    $person = $this->userWith2fa()->refresh();
    $own = $this->workspaceFor($person, 'Mi tienda');
    $agency = Account::query()->create(['type' => AccountType::Agency, 'name' => 'Imagina La Web', 'country' => 'CO', 'status' => 'active']);
    $agency->users()->attach($person->id, ['role' => 'agency_member']);

    addAgencyClients($this, $agency, 2);
    // La primera vez Pennant resuelve y guarda los módulos de cada espacio nuevo (una consulta y una inserción para
    // todos); se mide la carga de todos los días.
    $this->actingAs($person)->getJson('/api/me')->assertOk();
    $few = queryCount(fn () => $this->actingAs($person)->getJson('/api/me')->assertOk()->assertJsonCount(3, 'workspaces'));

    addAgencyClients($this, $agency, 9);
    DB::table('accounts')->where('id', $agency->id)->update(['billing_status' => 'grace', 'grace_ends_at' => now()->addDays(3)]);
    $this->actingAs($person)->getJson('/api/me')->assertOk();
    $res = null;
    $many = queryCount(function () use ($person, &$res) {
        $res = $this->actingAs($person)->getJson('/api/me')->assertOk()->assertJsonCount(12, 'workspaces');
    });

    expect($many)->toBe($few);
    $workspaces = collect($res->json('workspaces'));
    $viaAgency = $workspaces->where('account_id', 'acc_'.$agency->id);
    // Directo: dueña de su tienda. Por la agencia: agency_member → admin, con el estado de facturación de la agencia.
    expect($workspaces->firstWhere('id', $own->publicId())['role'])->toBe('owner')
        ->and($workspaces->firstWhere('id', $own->publicId())['billing']['status'])->toBe('active')
        ->and($viaAgency)->toHaveCount(11)
        ->and($viaAgency->pluck('role')->unique()->values()->all())->toBe(['admin'])
        ->and($viaAgency->pluck('billing.status')->unique()->values()->all())->toBe(['grace'])
        ->and($viaAgency->pluck('abilities')->every(fn (array $a) => in_array('viewAgency', $a, true)))->toBeTrue();
});

it('el panel de la plataforma lee el uso de todos los espacios con las mismas consultas para 2 o 8', function () {
    $admin = $this->userWith2fa(['is_platform_admin' => true])->refresh();
    $agency = Account::query()->create(['type' => AccountType::Agency, 'name' => 'Imagina La Web', 'country' => 'CO', 'status' => 'active']);
    addAgencyClients($this, $agency, 2);
    $first = Workspace::query()->where('account_id', $agency->id)->firstOrFail();
    DB::table('usage_counters')->insert(['workspace_id' => $first->id, 'period' => now()->setTimezone($first->timezone ?: 'America/Bogota')->format('Y-m'),
        'orders' => 37, 'computed_at' => now()]);
    $few = queryCount(fn () => $this->actingAs($admin)->getJson('/api/platform/overview')->assertOk());

    addAgencyClients($this, $agency, 6);
    $res = null;
    $many = queryCount(function () use ($admin, &$res) {
        $res = $this->actingAs($admin)->getJson('/api/platform/overview')->assertOk();
    });

    // Igual o menos (la primera petición deja en memoria lo que no cambia, como el plan por defecto).
    expect($many)->toBeLessThanOrEqual($few);
    $rows = collect(collect($res->json('accounts'))->firstWhere('id', $agency->publicId())['workspaces']);
    expect($rows)->toHaveCount(8)
        ->and($rows->firstWhere('id', $first->publicId())['usage']['value'])->toBe(37)
        ->and($rows->firstWhere('id', $first->publicId())['usage']['metric'])->toBe('orders');
});
