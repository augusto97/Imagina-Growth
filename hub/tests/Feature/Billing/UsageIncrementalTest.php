<?php

use App\Domain\Billing\Mail\UsageAlertMail;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Billing\Usage\UsageActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * R13: el uso de facturación ya no se recalcula para todos cada 5 minutos: solo los espacios con actividad (eventos
 * de sus sitios, mensajes enviados, IA); todos cada hora; y el mes anterior se vuelve a calcular al cerrar, para
 * que lo facturado incluya el final del mes y los pedidos que llegaron tarde.
 */

beforeEach(function () {
    $this->artisan('igr:plans:sync')->assertSuccessful();
    Mail::fake();
    UsageActivity::pull(100000); // lo que dejaron otras pruebas
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'America/Bogota'));
    $this->a = $this->workspaceFor($this->userWith2fa(), 'Tienda A');
    $this->b = $this->workspaceFor($this->userWith2fa(), 'Tienda B');
    foreach ([$this->a, $this->b] as $ws) {
        DB::table('accounts')->where('id', $ws->account_id)->update(['plan_id' => 'tienda_inicio']);
        app(PlanResolver::class)->refresh($ws);
    }
    [$this->siteA] = $this->connectedSite($this->a, 'tienda-a.co');
    [$this->siteB] = $this->connectedSite($this->b, 'tienda-b.co');
});

function usageOrders($ws, int $n, CarbonImmutable $at): void
{
    for ($i = 0; $i < $n; $i++) {
        DB::table('orders')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $ws->id, 'site_id' => 'S1', 'external_id' => (string) Str::uuid(),
            'status' => 'processing', 'is_paid' => true, 'currency' => 'COP', 'total' => 50000, 'created_at' => $at, 'updated_at' => $at]);
    }
}

function usageRow($ws, string $period): ?object
{
    return DB::table('usage_counters')->where('workspace_id', $ws->id)->where('period', $period)->first();
}

it('cada 5 minutos solo recalcula los espacios que recibieron eventos, enviaron mensajes o usaron IA', function () {
    $this->artisan('igr:billing:usage')->assertSuccessful();
    $computedB = usageRow($this->b, '2026-11')->computed_at;

    // A recibe pedidos por la ingesta (que actualiza last_event_at del sitio); B no hace nada.
    $this->travel(2)->minutes();
    usageOrders($this->a, 3, CarbonImmutable::now());
    DB::table('sites')->where('id', $this->siteA->id)->update(['last_event_at' => now()]);
    $this->travel(1)->minutes();
    $this->artisan('igr:billing:usage', ['--changed' => true])->assertSuccessful();
    expect((int) usageRow($this->a, '2026-11')->orders)->toBe(3)
        ->and(usageRow($this->b, '2026-11')->computed_at)->toBe($computedB);

    // B envía un mensaje: queda anotado y entra en la siguiente vuelta, una sola vez.
    DB::table('messages')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->b->id, 'channel' => 'email', 'direction' => 'out', 'kind' => 'marketing',
        'source_type' => 'flow', 'status' => 'sent', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    UsageActivity::touch($this->b->id);
    $this->travel(1)->minutes();
    $this->artisan('igr:billing:usage', ['--changed' => true])->assertSuccessful();
    expect((int) usageRow($this->b, '2026-11')->emails)->toBe(1)->and(UsageActivity::pull())->toBe([]);
    $again = usageRow($this->b, '2026-11')->computed_at;
    $this->travel(1)->minutes();
    $this->artisan('igr:billing:usage', ['--changed' => true])->assertSuccessful();
    expect(usageRow($this->b, '2026-11')->computed_at)->toBe($again);
});

it('al empezar el mes recalcula el anterior con los pedidos de las últimas horas y los que llegaron tarde, sin avisos', function () {
    // El último cálculo de noviembre fue a las 23:55; después entraron 2 pedidos de noviembre (uno llegó el 2 de diciembre).
    usageOrders($this->a, 299, CarbonImmutable::parse('2026-11-20 12:00:00', 'America/Bogota'));
    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:55:00', 'America/Bogota'));
    $this->artisan('igr:billing:usage')->assertSuccessful();
    expect((int) usageRow($this->a, '2026-11')->orders)->toBe(299);
    Mail::assertSent(UsageAlertMail::class, 1); // el 80 %
    usageOrders($this->a, 2, CarbonImmutable::parse('2026-11-30 23:58:00', 'America/Bogota'));

    $this->travelTo(CarbonImmutable::parse('2026-12-02 10:07:00', 'America/Bogota'));
    $this->artisan('igr:billing:usage')->assertSuccessful();
    expect((int) usageRow($this->a, '2026-11')->orders)->toBe(301)
        ->and(usageRow($this->a, '2026-12'))->not->toBeNull()
        // Recalcular el mes cerrado no manda el aviso del 100 % de un mes que ya pasó.
        ->and(Mail::sent(UsageAlertMail::class))->toHaveCount(1);

    // Pasados los primeros días, el mes anterior ya no se toca.
    usageOrders($this->a, 5, CarbonImmutable::parse('2026-11-29 12:00:00', 'America/Bogota'));
    $this->travelTo(CarbonImmutable::parse('2026-12-05 10:07:00', 'America/Bogota'));
    $this->artisan('igr:billing:usage')->assertSuccessful();
    expect((int) usageRow($this->a, '2026-11')->orders)->toBe(301);
});
