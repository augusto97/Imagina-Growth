<?php

use App\Analytics\Semantic\Period;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Commerce\Models\Order;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Queries\ServicesResults;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/*
 * Demo de un negocio de servicios (rediseño v2 §11.2): `igr:seed-demo --tipo=servicios --vertical=…` crea un espacio de
 * trabajo creíble en Colombia sin WooCommerce, sin duplicar al repetirse, y la tienda sigue siendo la de siempre.
 */

it('crea la clínica de prueba con leads, citas, cotizaciones, conversaciones, formularios y consentimiento', function () {
    $this->artisan('igr:seed-demo', ['--tipo' => 'servicios', '--vertical' => 'health', '--contacts' => 80])
        ->expectsOutputToContain('Clínica Sonrisa Demo')->assertSuccessful();
    $ws = Workspace::query()->where('name', 'Clínica Sonrisa Demo')->sole();
    expect($ws->vertical)->toBe('health')->and($ws->currency)->toBe('COP')->and($ws->country)->toBe('CO')->and($ws->industry)->toBe('salud_bienestar')
        ->and(DB::table('accounts')->where('id', $ws->account_id)->value('plan_id'))->toBe('servicios_pro')
        ->and(DB::table('sites')->where('workspace_id', $ws->id)->value('platform'))->toBe('wordpress');

    app(CurrentWorkspace::class)->run($ws, function () use ($ws) {
        $leads = Lead::query()->get();
        expect(LeadPipeline::query()->where('is_default', true)->value('name'))->toBe('Pacientes')
            ->and($leads->count())->toBeGreaterThan(40)
            ->and($leads->pluck('origin')->unique()->values()->all())->toContain('form', 'whatsapp', 'booking')
            ->and($leads->pluck('stage')->unique()->count())->toBeGreaterThanOrEqual(4)
            ->and($leads->whereNotNull('won_at')->count())->toBeGreaterThan(3)
            ->and($leads->whereNotNull('lost_at')->count())->toBeGreaterThan(3)
            ->and($leads->pluck('source')->pluck('channel')->unique()->values()->all())->toContain('paid_social', 'paid_search', 'whatsapp')
            ->and($leads->whereNotNull('won_at')->every(fn (Lead $l) => (float) $l->value_ws > 0))->toBeTrue()
            ->and(Appointment::query()->where('status', 'completed')->count())->toBeGreaterThan(0)
            ->and(Appointment::query()->where('status', 'no_show')->count())->toBeGreaterThan(0)
            ->and(Quote::query()->count())->toBeGreaterThan(0)
            ->and(Conversation::query()->count())->toBeGreaterThan(0)
            ->and(Order::query()->count())->toBe(0)
            ->and(DB::table('onsite_widgets')->where('workspace_id', $ws->id)->where('kind', 'embedded_form')->count())->toBe(2)
            ->and(DB::table('contact_consents')->where('workspace_id', $ws->id)->where('status', 'granted')->count())->toBeGreaterThan(20)
            ->and(DB::table('lead_activities')->where('workspace_id', $ws->id)->where('kind', 'stage')->count())->toBeGreaterThan(20);
        // Los resultados de servicios salen con datos.
        $results = app(ServicesResults::class)->handle($ws, Period::make('America/Bogota', now('America/Bogota')->subDays(179)->toDateString(), now('America/Bogota')->toDateString()));
        expect($results['leads']['total'])->toBeGreaterThan(30)->and($results['appointments']['completed'])->toBeGreaterThan(0)
            ->and(collect($results['attribution']['groups'])->sum('count'))->toBe($results['won']['count']);
    });

    // Repetirlo no duplica nada.
    $before = DB::table('leads')->where('workspace_id', $ws->id)->count();
    $this->artisan('igr:seed-demo', ['--tipo' => 'servicios', '--vertical' => 'health', '--contacts' => 80])->expectsOutputToContain('ya tenía datos')->assertSuccessful();
    expect(DB::table('leads')->where('workspace_id', $ws->id)->count())->toBe($before)
        ->and(Workspace::query()->where('name', 'Clínica Sonrisa Demo')->count())->toBe(1);
});

it('sirve para servicios profesionales e inmobiliaria y no acepta otro tipo ni la tienda como vertical', function () {
    foreach (['professional' => ['Consultores Andinos Demo', 'Clientes potenciales'], 'real_estate' => ['Inmobiliaria Horizonte Demo', 'Clientes potenciales']] as $vertical => [$name, $pipeline]) {
        $this->artisan('igr:seed-demo', ['--tipo' => 'servicios', '--vertical' => $vertical, '--contacts' => 40])->assertSuccessful();
        $ws = Workspace::query()->where('name', $name)->sole();
        expect($ws->vertical)->toBe($vertical)
            ->and(app(CurrentWorkspace::class)->run($ws, fn () => [LeadPipeline::query()->value('name'), Lead::query()->count() > 10]))->toBe([$pipeline, true]);
    }
    $this->artisan('igr:seed-demo', ['--tipo' => 'servicios', '--vertical' => 'store'])->assertFailed();
    $this->artisan('igr:seed-demo', ['--tipo' => 'servicios', '--vertical' => 'dentista'])->assertFailed();
    $this->artisan('igr:seed-demo', ['--tipo' => 'otra'])->assertFailed();
});

it('no corre en producción', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('igr:seed-demo', ['--tipo' => 'servicios'])->expectsOutputToContain('no se ejecuta en producción')->assertFailed();
    app()->detectEnvironment(fn () => 'testing');
    expect(Workspace::query()->where('name', 'Clínica Sonrisa Demo')->exists())->toBeFalse();
});
