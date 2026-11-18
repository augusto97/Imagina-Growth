<?php

declare(strict_types=1);

namespace App\Domain\Segments\Console;

use App\Analytics\Ch;
use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Support\SiteKeys;
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de rendimiento para la segmentación (M04: < 3 s con 100.000
 * contactos y 5 millones de eventos). Escribe directo en MariaDB y
 * ClickHouse con INSERT … SELECT: no pasa por el pipeline de ingesta.
 * Solo fuera de producción.
 */
final class PerfDatasetCommand extends Command
{
    protected $signature = 'igr:segments:perf-dataset
        {--workspace= : ws_… existente (por defecto crea «Perf segmentos»)}
        {--contacts=100000}
        {--orders=150000}
        {--events=5000000}';

    protected $description = 'Genera contactos, pedidos y eventos masivos para medir la segmentación (no en producción)';

    private const CITIES = ['Bogotá', 'Medellín', 'Cali', 'Barranquilla', 'Cartagena', 'Bucaramanga', 'Pereira', 'Manizales', 'Cúcuta', 'Ibagué'];

    private const REGIONS = ['CO-DC', 'CO-ANT', 'CO-VAC', 'CO-ATL', 'CO-BOL', 'CO-SAN', 'CO-RIS', 'CO-CAL', 'CO-NSA', 'CO-TOL'];

    public function handle(CurrentWorkspace $current, Ch $ch): int
    {
        if (app()->isProduction()) {
            $this->error('No se ejecuta en producción.');

            return self::FAILURE;
        }
        $contacts = max(1, (int) $this->option('contacts'));
        $orders = max(0, (int) $this->option('orders'));
        $events = max(0, (int) $this->option('events'));

        $workspace = $this->option('workspace')
            ? Workspace::query()->find(PrefixedId::decode('ws', (string) $this->option('workspace')))
            : $this->createWorkspace();
        if ($workspace === null) {
            $this->error('No existe ese espacio de trabajo.');

            return self::FAILURE;
        }
        $site = $current->run($workspace, fn () => Site::query()->first() ?? Site::query()->create([
            'workspace_id' => $workspace->id, 'platform' => 'woocommerce', 'name' => 'Perf', 'url' => 'https://perf.tienda.co',
            'host' => 'perf.tienda.co', 'domains' => ['perf.tienda.co'], 'public_key' => SiteKeys::publicKey(), 'secret' => SiteKeys::secret(),
            'status' => Site::STATUS_CONNECTED, 'timezone' => $workspace->timezone, 'currency' => $workspace->currency,
        ]));
        $ws = $workspace->id;
        $t = microtime(true);

        // Contactos con campos calculados variados (RFM, compras, etiquetas, consentimientos).
        DB::table('contacts')->where('workspace_id', $ws)->where('id', 'like', '01PERF%')->delete();
        $cities = "ELT(1 + seq % 10, '".implode("','", self::CITIES)."')";
        $regions = "ELT(1 + seq % 10, '".implode("','", self::REGIONS)."')";
        $rfm = "ELT(1 + seq % 11, 'champions','loyal','cant_lose','at_risk','potential_loyalists','new','promising','need_attention','about_to_sleep','hibernating','lost')";
        for ($start = 0; $start < $contacts; $start += 50000) {
            $end = min($contacts, $start + 50000) - 1;
            DB::statement("INSERT INTO contacts (id, workspace_id, email_bidx, phone_bidx, city, region_code, country, lifecycle_stage,
                first_seen_at, last_seen_at, orders_count, revenue_total_ws, aov_ws, first_order_at, last_order_at, coupon_orders_ratio,
                rfm_r, rfm_f, rfm_m, rfm_segment, tags, created_at, updated_at)
              SELECT CONCAT('01PERF', LPAD(seq, 20, '0')), ?, SHA2(CONCAT('e', seq), 256), IF(seq % 3 = 0, NULL, SHA2(CONCAT('p', seq), 256)),
                {$cities}, {$regions}, 'CO', IF(seq % 5 = 0, 'lead', IF(seq % 5 = 1, 'repeat', 'customer')),
                NOW() - INTERVAL (seq % 700) DAY, NOW() - INTERVAL (seq % 90) DAY, seq % 6, (seq % 6) * 95000, IF(seq % 6 = 0, 0, 95000),
                IF(seq % 6 = 0, NULL, NOW() - INTERVAL (seq % 600) DAY), IF(seq % 6 = 0, NULL, NOW() - INTERVAL (seq % 200) DAY),
                IF(seq % 6 = 0, NULL, (seq % 5) / 4), 1 + seq % 5, 1 + (seq DIV 5) % 5, 1 + (seq DIV 25) % 5, {$rfm},
                IF(seq % 4 = 0, JSON_ARRAY('mayorista'), JSON_ARRAY()), NOW(), NOW()
              FROM seq_{$start}_to_{$end}", [$ws]);
        }
        $this->line(sprintf('Contactos: %s en %.1f s', number_format($contacts, 0, ',', '.'), microtime(true) - $t));

        // Consentimientos: 40% correo, 30% WhatsApp.
        DB::table('contact_consents')->where('workspace_id', $ws)->where('contact_id', 'like', '01PERF%')->delete();
        foreach (['email' => 4, 'whatsapp' => 3] as $channel => $tenths) {
            DB::statement("INSERT INTO contact_consents (id, workspace_id, contact_id, channel, purpose, status, source, granted_at, created_at, updated_at)
              SELECT CONCAT('01PC', ?, LPAD(seq, 21, '0')), ?, CONCAT('01PERF', LPAD(seq, 20, '0')), ?, 'marketing', 'granted', 'import', NOW(), NOW(), NOW()
              FROM seq_0_to_".($contacts - 1).' WHERE seq % 10 < ?', [$channel === 'email' ? 'E' : 'W', $ws, $channel, $tenths]);
        }

        // Pedidos pagados con un ítem cada uno; la mitad en la categoría «favorita» del contacto.
        $t = microtime(true);
        DB::table('order_items')->where('workspace_id', $ws)->where('id', 'like', '01PERI%')->delete();
        DB::table('orders')->where('workspace_id', $ws)->where('id', 'like', '01PERO%')->delete();
        for ($start = 0; $start < $orders; $start += 50000) {
            $end = min($orders, $start + 50000) - 1;
            DB::statement("INSERT INTO orders (id, workspace_id, site_id, external_id, contact_id, status, is_paid, paid_at, currency, total, total_ws,
                payment_method_id, coupon_codes, items_count, created_at, updated_at, projected_at)
              SELECT CONCAT('01PERO', LPAD(seq, 20, '0')), ?, ?, CONCAT('p', seq), CONCAT('01PERF', LPAD((seq * 7919) % ?, 20, '0')), 'completed', 1,
                NOW() - INTERVAL (seq % 700) DAY, 'COP', 95000, 95000, ELT(1 + seq % 4, 'wompi', 'nequi', 'cod', 'bacs'),
                IF(seq % 5 = 0, JSON_ARRAY('BIENVENIDA10'), JSON_ARRAY()), 1, NOW() - INTERVAL (seq % 700) DAY, NOW(), NOW()
              FROM seq_{$start}_to_{$end}", [$ws, $site->id, $contacts]);
            DB::statement("INSERT INTO order_items (id, workspace_id, order_id, external_product_id, name, category_ids, brand, qty, unit_price, line_total)
              SELECT CONCAT('01PERI', LPAD(seq, 20, '0')), ?, CONCAT('01PERO', LPAD(seq, 20, '0')), CAST(1 + seq % 60 AS CHAR), 'Producto',
                JSON_ARRAY(CAST(10 + IF(seq % 2 = 0, ((seq * 7919) % {$contacts}) % 6, seq % 6) AS CHAR)), ELT(1 + seq % 3, 'Pintuco', 'Sika', 'Truper'), 1, 95000, 95000
              FROM seq_{$start}_to_{$end}", [$ws]);
        }
        $this->line(sprintf('Pedidos: %s en %.1f s', number_format($orders, 0, ',', '.'), microtime(true) - $t));

        // Eventos en ClickHouse: navegación del último año; 2/3 con contacto y 1/3 anónimos resueltos por identity_map.
        // La mitad de las vistas son del producto «favorito» del visitante (vistas repetidas).
        $t = microtime(true);
        // ch_id es autoincremental: se lee de la base (un modelo recién creado no lo trae).
        $wsCh = (int) DB::table('workspaces')->where('id', $ws)->value('ch_id');
        $siteCh = (int) DB::table('sites')->where('id', $site->id)->value('ch_id');
        $ch->statement("ALTER TABLE events DELETE WHERE workspace_id = {$wsCh} AND startsWith(anonymous_id, 'perf-')");
        $ch->statement("ALTER TABLE identity_map DELETE WHERE workspace_id = {$wsCh} AND startsWith(anonymous_id, 'perf-')");
        $ch->statement("INSERT INTO identity_map (workspace_id, anonymous_id, contact_id, linked_at)
            SELECT {$wsCh}, concat('perf-', toString(number)), concat('01PERF', leftPad(toString(number), 20, '0')), now64(3)
            FROM numbers({$contacts})");
        for ($start = 0; $start < $events; $start += 1000000) {
            $n = min(1000000, $events - $start);
            $ch->statement("INSERT INTO events (workspace_id, site_id, event_id, event, occurred_at, received_at, source_type, anonymous_id, session_id,
                    contact_id, page_type, path, channel, device_type, city, product_id, currency, properties)
                SELECT {$wsCh}, {$siteCh}, generateUUIDv4(),
                    arrayElement(['page.viewed','page.viewed','page.viewed','product.viewed','product.viewed','cart.item_added','checkout.started','search.performed','category.viewed','order.created'], 1 + (number % 10)),
                    now64(3) - toIntervalSecond((number * 7907) % 31536000), now64(3), 'browser',
                    concat('perf-', toString(((number + {$start}) * 104729) % ({$contacts} * 2))),
                    concat('s', toString(((number + {$start}) * 104729) % ({$contacts} * 2)), '-', toString(intDiv((number * 7907) % 31536000, 1800))),
                    if(number % 3 = 0, '', concat('01PERF', leftPad(toString(((number + {$start}) * 104729) % ({$contacts} * 2) % {$contacts}), 20, '0'))),
                    'product', '/producto', 'organic_search', 'mobile', 'Bogotá',
                    toString(1 + if(number % 2 = 0, ((number + {$start}) * 104729) % ({$contacts} * 2) % 60, number % 60)), 'COP', CAST('{}' AS JSON)
                FROM numbers({$n})");
        }
        $this->line(sprintf('Eventos: %s en %.1f s', number_format($events, 0, ',', '.'), microtime(true) - $t));
        $this->info('Listo: '.$workspace->publicId().' — mide con: php artisan igr:segments:benchmark --workspace='.$workspace->publicId());

        return self::SUCCESS;
    }

    private function createWorkspace(): Workspace
    {
        $user = User::query()->firstOrCreate(['email' => 'perf@growth.test'], ['name' => 'Perf', 'password' => Hash::make('perf-growth-2026')]);

        return app(CreateWorkspace::class)->handle($user, new WorkspaceData(name: 'Perf segmentos'));
    }
}
