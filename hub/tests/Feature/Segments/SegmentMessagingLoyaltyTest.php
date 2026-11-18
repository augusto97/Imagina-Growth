<?php

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Models\Flow;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Segments\Actions\EvaluateContactSegments;
use App\Domain\Segments\Compiler\CompileContext;
use App\Domain\Segments\Compiler\SegmentCompiler;
use App\Domain\Segments\Definition\DefinitionValidator;
use App\Domain\Segments\Definition\InvalidDefinition;
use App\Domain\Segments\Jobs\EvaluateContactSegmentsJob;
use App\Domain\Segments\Support\DependencyIndex;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Condiciones de mensajería (Fase 2) y fidelización (Fase 3) de M04: validación
 * en español, compilación contra MariaDB real y recálculo en tiempo real.
 */

function segmentsXIn(Workspace $ws, callable $fn): mixed
{
    return app(CurrentWorkspace::class)->run($ws, $fn);
}

function segmentsXContact(Workspace $ws, string $name, array $attrs = []): Contact
{
    return segmentsXIn($ws, fn () => Contact::query()->create($attrs + [
        'workspace_id' => $ws->id, 'first_name' => $name, 'lifecycle_stage' => 'customer', 'first_seen_at' => now()->subYear(), 'last_seen_at' => now(),
    ]));
}

/** Un mensaje saliente con las fechas de lo que pasó (sent_at por defecto hace 2 días). */
function segmentsXMessage(Workspace $ws, Contact $c, array $attrs = []): void
{
    $sent = now()->subDays(2);
    DB::table('messages')->insert($attrs + [
        'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $ws->id, 'contact_id' => $c->id, 'channel' => 'email',
        'direction' => 'out', 'kind' => 'marketing', 'source_type' => 'flow', 'status' => 'delivered', 'idempotency_key' => (string) Str::uuid(),
        'sent_at' => $sent, 'created_at' => $sent, 'updated_at' => $sent,
    ]);
}

/** @return list<string> nombres de los contactos que cumplen, en orden (el nombre va cifrado en la base) */
function segmentsXMatch(Workspace $ws, array $condition, ?string $contactId = null): array
{
    return segmentsXIn($ws, function () use ($ws, $condition, $contactId) {
        $ast = app(DefinitionValidator::class)->validate(['op' => 'and', 'conditions' => [$condition]]);
        $ctx = $contactId ? CompileContext::forContact($ws, $contactId) : new CompileContext($ws);
        try {
            $ids = app(SegmentCompiler::class)->query($ast, $ctx)->pluck('c.id')->map(fn ($i) => (string) $i)->all();
        } finally {
            $ctx->cleanup();
        }
        $names = Contact::query()->whereKey($ids)->get()->map(fn (Contact $c) => (string) $c->first_name)->sort()->values()->all();

        return $names;
    });
}

function segmentsXErrors(Workspace $ws, array $condition): array
{
    return segmentsXIn($ws, function () use ($condition) {
        try {
            app(DefinitionValidator::class)->validate(['op' => 'and', 'conditions' => [$condition]]);
        } catch (InvalidDefinition $e) {
            return $e->errors;
        }

        return [];
    });
}

function segmentsXTiers(Workspace $ws): array
{
    return segmentsXIn($ws, function () use ($ws) {
        $program = LoyaltyProgram::query()->create(['workspace_id' => $ws->id, 'status' => 'active', 'currency_name' => 'Puntos', 'earn_rules' => [], 'redeem_rules' => [], 'expiry_policy' => [], 'wallet_rules' => []]);
        $mk = fn (string $name, int $rank) => LoyaltyTier::query()->create(['workspace_id' => $ws->id, 'program_id' => $program->id, 'name' => $name, 'rank' => $rank, 'threshold_type' => 'spend_12m', 'threshold' => $rank * 500000]);

        return ['plata' => $mk('Plata', 1), 'oro' => $mk('Oro', 2)];
    });
}

beforeEach(function () {
    $this->ws = $this->workspaceFor($this->userWith2fa());
    $ws = $this->ws;
    $this->ana = segmentsXContact($ws, 'Ana');
    $this->beto = segmentsXContact($ws, 'Beto');
    $this->caro = segmentsXContact($ws, 'Caro');
    $this->dani = segmentsXContact($ws, 'Dani');
});

it('mensajería: recibió, abrió, leyó, clics, respuestas, flujo, campaña y «nada en N días»', function () {
    $ws = $this->ws;
    $flow = segmentsXIn($ws, fn () => Flow::query()->create(['workspace_id' => $ws->id, 'name' => 'Carrito abandonado', 'status' => 'active', 'trigger' => ['type' => 'manual']]));
    $campaign = segmentsXIn($ws, fn () => Campaign::query()->create(['workspace_id' => $ws->id, 'name' => 'Black Friday', 'channel' => 'whatsapp', 'audience' => []]));

    // Ana: dos correos del flujo, abiertos y con clic. Beto: WhatsApp de la campaña, leído y respondido.
    segmentsXMessage($ws, $this->ana, ['flow_id' => $flow->id, 'opened_at' => now()->subDays(2), 'clicked_at' => now()->subDays(2)]);
    segmentsXMessage($ws, $this->ana, ['flow_id' => $flow->id, 'opened_at' => now()->subDay(), 'clicked_at' => now()->subDay(), 'sent_at' => now()->subDay()]);
    segmentsXMessage($ws, $this->beto, ['channel' => 'whatsapp', 'source_type' => 'campaign', 'campaign_id' => $campaign->id, 'read_at' => now()->subDay(), 'replied_at' => now()->subDay()]);
    // Caro: un correo de hace 40 días y un aviso de servicio reciente (no es marketing).
    segmentsXMessage($ws, $this->caro, ['sent_at' => now()->subDays(40), 'created_at' => now()->subDays(40)]);
    segmentsXMessage($ws, $this->caro, ['kind' => 'utility', 'sent_at' => now()->subDays(3)]);
    // Un mensaje en cola (sin enviar) no cuenta como recibido.
    segmentsXMessage($ws, $this->dani, ['status' => 'queued', 'sent_at' => null]);

    $c = fn (array $x) => $x + ['type' => 'messaging', 'channel' => 'any', 'kind' => 'any', 'operator' => 'ever'];
    expect(segmentsXMatch($ws, $c(['action' => 'received'])))->toBe(['Ana', 'Beto', 'Caro'])
        ->and(segmentsXMatch($ws, $c(['action' => 'opened', 'channel' => 'email'])))->toBe(['Ana'])
        ->and(segmentsXMatch($ws, $c(['action' => 'read', 'channel' => 'whatsapp'])))->toBe(['Beto'])
        ->and(segmentsXMatch($ws, $c(['action' => 'clicked', 'operator' => 'count_gte', 'value' => 2, 'window' => ['kind' => 'last_days', 'days' => 30]])))->toBe(['Ana'])
        ->and(segmentsXMatch($ws, $c(['action' => 'clicked', 'operator' => 'count_eq', 'value' => 1])))->toBe([])
        ->and(segmentsXMatch($ws, $c(['action' => 'replied'])))->toBe(['Beto'])
        ->and(segmentsXMatch($ws, $c(['action' => 'received', 'flow_id' => $flow->publicId()])))->toBe(['Ana'])
        ->and(segmentsXMatch($ws, $c(['action' => 'received', 'campaign_id' => $campaign->publicId()])))->toBe(['Beto'])
        // «No ha recibido nada de marketing en 30 días»: Caro (solo uno viejo y uno de servicio) y Dani (nada enviado).
        ->and(segmentsXMatch($ws, $c(['action' => 'received', 'kind' => 'marketing', 'operator' => 'never', 'window' => ['kind' => 'last_days', 'days' => 30]])))->toBe(['Caro', 'Dani'])
        // Restringido a un solo contacto (recálculo en tiempo real).
        ->and(segmentsXMatch($ws, $c(['action' => 'replied']), $this->beto->id))->toBe(['Beto'])
        ->and(segmentsXMatch($ws, $c(['action' => 'replied']), $this->ana->id))->toBe([]);

    // Puntaje de interacción en 90 días: Ana 2 × (abrir 1 + clic 3) = 8; Beto leer 1 + respuesta 5 = 6.
    $score = fn (string $op, mixed $v) => ['type' => 'messaging', 'action' => 'engagement', 'operator' => $op, 'value' => $v];
    expect(segmentsXMatch($ws, $score('gte', 6)))->toBe(['Ana', 'Beto'])
        ->and(segmentsXMatch($ws, $score('gt', 6)))->toBe(['Ana'])
        ->and(segmentsXMatch($ws, $score('between', [5, 7])))->toBe(['Beto'])
        ->and(segmentsXMatch($ws, $score('lte', 0)))->toBe(['Caro', 'Dani']);
});

it('fidelización: nivel (es, no es, al menos), puntos, saldo, puntos por vencer y referidos', function () {
    $ws = $this->ws;
    $tiers = segmentsXTiers($ws);
    segmentsXIn($ws, function () use ($tiers) {
        $this->ana->forceFill(['tier_id' => $tiers['oro']->id, 'points_balance' => 1500, 'wallet_balance_ws' => 35000, 'points_expiring' => 100, 'points_expiring_at' => now()->addDays(10)])->save();
        $this->beto->forceFill(['tier_id' => $tiers['plata']->id, 'points_balance' => 200, 'points_expiring' => 50, 'points_expiring_at' => now()->subDay()])->save();
    });
    $ref = fn (Contact $referrer, ?Contact $friend, string $status) => DB::table('referrals')->insert([
        'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $ws->id, 'referrer_contact_id' => $referrer->id, 'friend_contact_id' => $friend?->id,
        'code' => 'ANA7K2', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ref($this->ana, $this->beto, 'rewarded');
    $ref($this->ana, $this->caro, 'signed_up');

    $l = fn (string $field, string $op, mixed $v = null) => ['type' => 'loyalty', 'field' => $field, 'operator' => $op] + ($v === null ? [] : ['value' => $v]);
    expect(segmentsXMatch($ws, $l('tier', 'in', [$tiers['oro']->publicId()])))->toBe(['Ana'])
        ->and(segmentsXMatch($ws, $l('tier', 'not_in', [$tiers['oro']->publicId()])))->toBe(['Beto', 'Caro', 'Dani'])
        ->and(segmentsXMatch($ws, $l('tier', 'at_least', $tiers['plata']->publicId())))->toBe(['Ana', 'Beto'])
        ->and(segmentsXMatch($ws, $l('tier', 'at_least', $tiers['oro']->publicId())))->toBe(['Ana'])
        ->and(segmentsXMatch($ws, $l('tier', 'is_not_set')))->toBe(['Caro', 'Dani'])
        ->and(segmentsXMatch($ws, $l('points_balance', 'gte', 1000)))->toBe(['Ana'])
        ->and(segmentsXMatch($ws, $l('points_balance', 'between', [100, 300])))->toBe(['Beto'])
        ->and(segmentsXMatch($ws, $l('wallet_balance', 'gt', 0)))->toBe(['Ana'])
        // Los de Beto ya vencieron: no cuentan como «por vencer».
        ->and(segmentsXMatch($ws, $l('points_expiring', 'within_next_days', 15)))->toBe(['Ana'])
        ->and(segmentsXMatch($ws, $l('points_expiring', 'within_next_days', 5)))->toBe([])
        // Solo cuentan los referidos que compraron (calificados o premiados).
        ->and(segmentsXMatch($ws, $l('referrals_made', 'gte', 1)))->toBe(['Ana'])
        ->and(segmentsXMatch($ws, $l('referrals_made', 'gte', 2)))->toBe([])
        ->and(segmentsXMatch($ws, $l('referrals_made', 'eq', 0)))->toBe(['Beto', 'Caro', 'Dani'])
        ->and(segmentsXMatch($ws, $l('was_referred', 'is_true')))->toBe(['Beto', 'Caro'])
        ->and(segmentsXMatch($ws, $l('was_referred', 'is_false')))->toBe(['Ana', 'Dani']);
});

it('explica en español qué está mal en mensajería y fidelización', function (array $condition, string $path, string $fragment) {
    $errors = segmentsXErrors($this->ws, $condition);
    expect($errors)->toHaveKey($path)->and($errors[$path])->toContain($fragment);
})->with([
    'acción desconocida' => [['type' => 'messaging', 'action' => 'reenvió', 'operator' => 'ever'], 'conditions.0.action', 'recibió, abrió, leyó'],
    'aperturas en WhatsApp' => [['type' => 'messaging', 'action' => 'opened', 'channel' => 'whatsapp', 'operator' => 'ever'], 'conditions.0.action', 'solo se miden en el correo'],
    'leído en correo' => [['type' => 'messaging', 'action' => 'read', 'channel' => 'email', 'operator' => 'ever'], 'conditions.0.action', 'se mide en WhatsApp'],
    'canal inválido' => [['type' => 'messaging', 'action' => 'received', 'channel' => 'fax', 'operator' => 'ever'], 'conditions.0.channel', 'Canal inválido'],
    'flujo inexistente' => [['type' => 'messaging', 'action' => 'received', 'flow_id' => 'fl_01JD3QG7Y2V8K6N4M5P9R0S1T2', 'operator' => 'ever'], 'conditions.0.flow_id', 'El flujo no existe'],
    'campaña inexistente' => [['type' => 'messaging', 'action' => 'received', 'campaign_id' => 'cmp_nada', 'operator' => 'ever'], 'conditions.0.campaign_id', 'La campaña no existe'],
    'conteo sin número' => [['type' => 'messaging', 'action' => 'clicked', 'operator' => 'count_gte', 'value' => 'dos'], 'conditions.0.value', 'número de veces'],
    'puntaje fuera de rango' => [['type' => 'messaging', 'action' => 'engagement', 'operator' => 'gte', 'value' => 150], 'conditions.0.value', 'de 0 a 100'],
    'puntaje con operador de fecha' => [['type' => 'messaging', 'action' => 'engagement', 'operator' => 'before', 'value' => '2026-01-01'], 'conditions.0.operator', 'puntaje de interacción'],
    'campo desconocido' => [['type' => 'loyalty', 'field' => 'cashback', 'operator' => 'gte', 'value' => 1], 'conditions.0.field', 'Dato de fidelización desconocido'],
    'operador de puntos' => [['type' => 'loyalty', 'field' => 'points_balance', 'operator' => 'contains', 'value' => 1], 'conditions.0.operator', 'Operador inválido'],
    'nivel sin elegir' => [['type' => 'loyalty', 'field' => 'tier', 'operator' => 'in', 'value' => []], 'conditions.0.value', 'al menos un nivel'],
    'nivel inexistente' => [['type' => 'loyalty', 'field' => 'tier', 'operator' => 'at_least', 'value' => 'tr_01JD3QG7Y2V8K6N4M5P9R0S1T2'], 'conditions.0.value', 'ya no existe'],
    'días de vencimiento' => [['type' => 'loyalty', 'field' => 'points_expiring', 'operator' => 'within_next_days', 'value' => 0], 'conditions.0.value', 'entre 1 y 3.650'],
]);

it('no mezcla espacios de trabajo: ni los mensajes ni los niveles de otro', function () {
    $other = $this->workspaceFor($this->userWith2fa());
    $intruso = segmentsXContact($other, 'Intruso');
    segmentsXMessage($other, $intruso, ['clicked_at' => now()]);
    $otherTiers = segmentsXTiers($other);
    segmentsXIn($other, fn () => $intruso->forceFill(['tier_id' => $otherTiers['oro']->id, 'points_balance' => 9999])->save());

    expect(segmentsXMatch($this->ws, ['type' => 'messaging', 'action' => 'clicked', 'operator' => 'ever']))->toBe([])
        ->and(segmentsXMatch($this->ws, ['type' => 'loyalty', 'field' => 'points_balance', 'operator' => 'gte', 'value' => 1]))->toBe([])
        // El nivel de otro espacio de trabajo no se puede usar.
        ->and(segmentsXErrors($this->ws, ['type' => 'loyalty', 'field' => 'tier', 'operator' => 'in', 'value' => [$otherTiers['oro']->publicId()]]))->toHaveKey('conditions.0.value');
});

it('recalcula en tiempo real con eventos de mensajes y de fidelización', function () {
    $index = [
        ['id' => 'a', 'events' => [], 'kinds' => ['messaging']],
        ['id' => 'b', 'events' => [], 'kinds' => ['loyalty']],
    ];
    expect(DependencyIndex::isRelevant([$index[0]], 'message.clicked'))->toBeTrue()
        ->and(DependencyIndex::isRelevant([$index[0]], 'whatsapp.inbound'))->toBeTrue()
        ->and(DependencyIndex::isRelevant([$index[0]], 'points.earned'))->toBeFalse()
        ->and(DependencyIndex::isRelevant([$index[1]], 'points.earned'))->toBeTrue()
        ->and(DependencyIndex::isRelevant([$index[1]], 'wallet.credited'))->toBeTrue()
        ->and(DependencyIndex::isRelevant([$index[1]], 'tier.changed'))->toBeTrue()
        ->and(DependencyIndex::isRelevant([$index[1]], 'referral.converted'))->toBeTrue()
        ->and(DependencyIndex::isRelevant([$index[1]], 'message.sent'))->toBeFalse();

    $this->useIngestInfrastructure();
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $ana = segmentsXContact($ws, 'Ana');
    $base = "/api/w/{$ws->publicId()}/segments";
    $id = $this->actingAs($owner)->postJson($base, ['name' => 'Con 500 puntos', 'definition' => ['op' => 'and', 'conditions' => [
        ['type' => 'loyalty', 'field' => 'points_balance', 'operator' => 'gte', 'value' => 500],
    ]]])->assertCreated()->json('id');
    $this->actingAs($owner)->getJson("{$base}/{$id}")->assertJsonPath('member_count', 0);

    // Gana puntos: el libro actualiza el saldo y emite points.earned, que encola el recálculo de Ana.
    Queue::fake([EvaluateContactSegmentsJob::class]);
    segmentsXIn($ws, fn () => $ana->forceFill(['points_balance' => 600])->save());
    app(HubEvents::class)->emit($ws, 'points.earned', ['ledger_id' => 'le_1', 'points' => 600, 'balance_after' => 600, 'status' => 'confirmed', 'reason' => 'order'], $ana->id);
    Queue::assertPushed(EvaluateContactSegmentsJob::class, fn (EvaluateContactSegmentsJob $job) => $job->contactId === $ana->id);
    Queue::pushed(EvaluateContactSegmentsJob::class)->each(
        fn (EvaluateContactSegmentsJob $job) => segmentsXIn($ws, fn () => $job->handle(app(EvaluateContactSegments::class))),
    );
    $this->actingAs($owner)->getJson("{$base}/{$id}")->assertJsonPath('member_count', 1);
});

it('las opciones del constructor traen niveles, flujos, campañas y acciones', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $tiers = segmentsXTiers($ws);
    segmentsXIn($ws, fn () => Flow::query()->create(['workspace_id' => $ws->id, 'name' => 'Bienvenida', 'status' => 'active', 'trigger' => ['type' => 'manual']]));

    $res = $this->actingAs($owner)->getJson("/api/w/{$ws->publicId()}/segments/options")->assertOk();
    expect($res->json('loyalty_tiers'))->toBe([
        ['id' => $tiers['plata']->publicId(), 'name' => 'Plata', 'rank' => 1],
        ['id' => $tiers['oro']->publicId(), 'name' => 'Oro', 'rank' => 2],
    ])
        ->and($res->json('message_actions'))->toBe(['received', 'opened', 'read', 'clicked', 'replied'])
        ->and($res->json('flows.0.name'))->toBe('Bienvenida')
        ->and(collect($res->json('loyalty_fields'))->pluck('key')->all())->toContain('tier', 'points_balance', 'referrals_made');
});

it('el benchmark mide también los segmentos de mensajería y fidelización', function () {
    $this->useIngestInfrastructure();
    segmentsXMessage($this->ws, $this->ana, ['clicked_at' => now()->subDay()]);
    $this->artisan('igr:segments:benchmark', ['--workspace' => $this->ws->publicId(), '--runs' => 1])
        ->expectsOutputToContain('Hizo clic en un mensaje de marketing en 30 días')
        ->expectsOutputToContain('Con 500 puntos o más')
        ->assertSuccessful();
});
