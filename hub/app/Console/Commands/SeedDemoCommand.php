<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\Builders\KpiDailyBuilder;
use App\Analytics\Builders\SessionsBuilder;
use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Intelligence\Rfm\RfmCalculator;
use App\Domain\Onboarding\Support\Verticals;
use App\Domain\Sites\Actions\UpdateBackfillState;
use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Queries\ReconciliationQuery;
use App\Domain\Sites\Support\SiteKeys;
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\IngestStream;
use App\Support\Demo\DemoBrowsingWriter;
use App\Support\Demo\DemoServicesSeeder;
use App\Support\Demo\DemoStoreGenerator;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Tienda colombiana de prueba (CLAUDE.md §6) que entra por el mismo pipeline
 * de ingesta que un backfill real (`--tipo=tienda`, por defecto), o un negocio
 * de servicios sin WooCommerce (`--tipo=servicios --vertical=health`,
 * DemoServicesSeeder: leads, citas, cotizaciones, conversaciones, formularios y
 * consentimiento). No se permite en producción.
 */
final class SeedDemoCommand extends Command
{
    protected $signature = 'igr:seed-demo
        {--tipo=tienda : tienda (WooCommerce, «Pinturas Demo») o servicios (sin tienda)}
        {--vertical=health : Con --tipo=servicios: health, professional, real_estate u otra vertical del asistente}
        {--contacts=300 : Con --tipo=servicios: contactos de la demo}
        {--workspace= : ws_… existente; si falta, crea «Pinturas Demo» con demo@growth.test}
        {--customers=2000}
        {--orders=10000}
        {--months=24}
        {--seed=2026}
        {--browsing=direct : Navegación demo: direct (directo a ClickHouse) o none}
        {--browsing-months=12 : Meses de navegación hacia atrás}
        {--process : Procesa el stream al terminar, arma sesiones, KPI, RFM y hallazgos, y muestra la conciliación}';

    protected $description = 'Genera una tienda colombiana creíble (clientes, pedidos COP, festivos, PSE, Nequi, contraentrega) y la ingiere';

    public function handle(IngestStream $stream, CurrentWorkspace $current, UpdateBackfillState $backfill, ReconciliationQuery $reconciliation): int
    {
        if (app()->isProduction()) {
            $this->error('igr:seed-demo no se ejecuta en producción.');

            return self::FAILURE;
        }
        $tipo = (string) $this->option('tipo');
        if (! in_array($tipo, ['tienda', 'servicios'], true)) {
            $this->error('--tipo debe ser tienda o servicios.');

            return self::FAILURE;
        }
        if ($tipo === 'servicios') {
            return $this->services();
        }

        $workspace = $this->workspace();
        if ($workspace === null) {
            return self::FAILURE;
        }

        return $current->run($workspace, function () use ($workspace, $stream, $backfill, $reconciliation): int {
            $site = Site::query()->where('host', 'demo.tienda.co')->first() ?? Site::query()->create([
                'workspace_id' => $workspace->id, 'platform' => 'woocommerce', 'name' => 'Tienda demo',
                'url' => 'https://demo.tienda.co', 'host' => 'demo.tienda.co', 'domains' => ['demo.tienda.co'],
                'public_key' => SiteKeys::publicKey(), 'secret' => SiteKeys::secret(), 'status' => Site::STATUS_CONNECTED,
                'timezone' => $workspace->timezone, 'currency' => 'COP', 'connected_at' => now(),
            ]);
            // ch_id es autoincremental fuera de la clave primaria: hay que leerlo de la base.
            $site->refresh();

            $until = CarbonImmutable::now('America/Bogota')->startOfHour();
            $generator = new DemoStoreGenerator($site->publicId(), $until, (int) $this->option('months'), (int) $this->option('seed'));
            $browsing = $this->option('browsing') === 'direct' ? $generator->withBrowsing((int) $this->option('browsing-months')) : null;
            $started = microtime(true);
            $buffer = [];
            $count = 0;
            foreach ($generator->events((int) $this->option('customers'), (int) $this->option('orders')) as $event) {
                $buffer[] = (string) json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (count($buffer) === 500) {
                    $stream->push($site, $workspace->ch_id, $buffer);
                    $count += 500;
                    $buffer = [];
                }
            }
            $stream->push($site, $workspace->ch_id, $buffer);
            $count += count($buffer);
            $this->info(sprintf('%d eventos generados y encolados en %.1f s.', $count, microtime(true) - $started));

            if ($browsing !== null) {
                $started = microtime(true);
                $rows = app(DemoBrowsingWriter::class)->write($workspace, $site, $browsing, $until->subMonths((int) $this->option('browsing-months'))->startOfDay());
                $this->info(sprintf('%d eventos de navegación escritos directo en ClickHouse en %.1f s.', $rows, microtime(true) - $started));
            }

            $from = $until->subMonths((int) $this->option('months'))->toDateString();
            $backfill->handle($site, [
                'status' => 'completed', 'from' => $from, 'phase' => 'done',
                'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
                'entities' => ['orders' => ['total' => (int) $this->option('orders'), 'sent' => (int) $this->option('orders')]],
                'reconciliation' => ['currency' => 'COP', 'generated_at' => $until->addMinute()->utc()->toIso8601String()] + $generator->reconciliation(),
            ]);

            if (! $this->option('process')) {
                $this->line('Procesa la cola con: php artisan igr:ingest:work --once');

                return self::SUCCESS;
            }

            $started = microtime(true);
            Artisan::call('igr:ingest:work', ['--once' => true], $this->output);
            $this->info(sprintf('Ingesta procesada en %.1f s.', microtime(true) - $started));

            $started = microtime(true);
            $since = $until->subMonths(max((int) $this->option('months'), (int) $this->option('browsing-months')) + 1);
            app(SessionsBuilder::class)->build($workspace, $since, CarbonImmutable::now()->addDay());
            app(KpiDailyBuilder::class)->build($workspace, (int) $since->diffInDays(now()) + 2);
            app(RfmCalculator::class)->run($workspace);
            app(InsightEngine::class)->run($workspace);
            $this->info(sprintf('Sesiones, KPI diarios, RFM y hallazgos listos en %.1f s.', microtime(true) - $started));

            $result = $reconciliation->handle($site->fresh() ?? $site);
            $this->table(['Estado', 'WooCommerce', 'Hub', 'Total WC', 'Total hub', 'OK'], array_map(fn ($r) => [
                $r['status'], $r['wc_count'], $r['hub_count'], $r['wc_total'], $r['hub_total'], $r['ok'] ? '✓' : '✗',
            ], $result['rows'] ?? []));
            $ok = (bool) ($result['ok'] ?? false);
            $ok ? $this->info('Conciliación: coincide al 100%.') : $this->error('Conciliación: hay diferencias.');

            return $ok ? self::SUCCESS : self::FAILURE;
        });
    }

    /** Negocio de servicios de prueba (sin WooCommerce): no pasa por la ingesta, va directo a las tablas. */
    private function services(): int
    {
        $vertical = (string) $this->option('vertical');
        if (! Verticals::valid($vertical) || $vertical === Verticals::STORE) {
            $this->error('--vertical debe ser una vertical de servicios: '.implode(', ', array_diff(Verticals::keys(), [Verticals::STORE])).'.');

            return self::FAILURE;
        }
        $started = microtime(true);
        $result = app(DemoServicesSeeder::class)->handle($vertical, max(20, (int) $this->option('contacts')), (int) $this->option('seed'));
        $this->line('Usuario demo: demo@growth.test / demo-growth-2026 (activa la 2FA al entrar).');
        if (! $result['created']) {
            $this->info(sprintf('«%s» ya tenía datos: no se agregó nada.', $result['workspace']->name));
        }
        $this->info(sprintf('«%s» (%s): %d contactos, %d leads, %d citas, %d cotizaciones y %d conversaciones en %.1f s.', $result['workspace']->name, $result['workspace']->publicId(),
            $result['contacts'], $result['leads'], $result['appointments'], $result['quotes'], $result['conversations'], microtime(true) - $started));

        return self::SUCCESS;
    }

    private function workspace(): ?Workspace
    {
        if ($this->option('workspace')) {
            $ws = Workspace::findByPublicId((string) $this->option('workspace'));
            if ($ws === null) {
                $this->error('No existe ese espacio de trabajo.');
            }

            return $ws;
        }
        $user = User::query()->firstOrCreate(['email' => 'demo@growth.test'], ['name' => 'Dueño demo', 'password' => Hash::make('demo-growth-2026')]);
        $existing = $user->workspaces()->where('workspaces.name', 'Pinturas Demo')->first();
        if ($existing instanceof Workspace) {
            return $this->withDemoPlan($existing);
        }
        $this->line('Usuario demo: demo@growth.test / demo-growth-2026 (activa la 2FA al entrar).');

        return $this->withDemoPlan(app(CreateWorkspace::class)->handle($user, new WorkspaceData(name: 'Pinturas Demo', industry: 'ferreteria_pinturas')));
    }

    /** Sin plan, la tienda demo quedaría en el plan gratuito (S3): lleva el plan Pro para mostrar todos los módulos. */
    private function withDemoPlan(Workspace $workspace): Workspace
    {
        if (PlanCatalog::ensure('tienda_pro')) {
            DB::table('accounts')->where('id', $workspace->account_id)->whereNull('plan_id')->update(['plan_id' => 'tienda_pro', 'updated_at' => now()]);
            app(PlanResolver::class)->refresh($workspace);
        }

        return $workspace;
    }
}
