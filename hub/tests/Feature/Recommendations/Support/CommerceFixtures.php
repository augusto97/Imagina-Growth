<?php

declare(strict_types=1);

namespace Tests\Feature\Recommendations\Support;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tienda de prueba para M16 y las audiencias: catálogo, pedidos y contactos
 * escritos directo en MariaDB (sin pasar por la ingesta) para controlar fechas.
 *
 * @property User $owner
 * @property Workspace $ws
 * @property Site $site
 * @property string $secret
 */
trait CommerceFixtures
{
    public function commerceSetUp(): void
    {
        $this->owner = $this->userWith2fa();
        $this->ws = $this->workspaceFor($this->owner);
        [$this->site, $this->secret] = $this->connectedSite($this->ws);
    }

    public function commerceRun(callable $fn): mixed
    {
        return app(CurrentWorkspace::class)->run($this->ws->fresh(), $fn);
    }

    /** @param list<string> $categories */
    public function commerceProduct(string $id, string $name, array $categories = ['12'], array $attributes = [], ?Site $site = null): void
    {
        $site ??= $this->site;
        DB::table('products')->insert($attributes + [
            'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'external_id' => $id, 'type' => 'simple',
            'name' => $name, 'permalink' => "https://tienda.co/p/{$id}", 'image_url' => "https://tienda.co/img/{$id}.jpg", 'price' => '10000.00',
            'currency' => 'COP', 'stock_status' => 'instock', 'status' => 'publish', 'category_ids' => json_encode($categories), 'content_hash' => md5($id),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Pedido con sus líneas: [product_id => cantidad].
     *
     * @param  array<string, int>  $items
     */
    public function commerceOrder(?string $contactId, array $items, int $daysAgo = 1, bool $paid = true, ?Site $site = null): string
    {
        $site ??= $this->site;
        $id = strtoupper((string) Str::ulid());
        $at = now()->subDays($daysAgo);
        $total = 0;
        foreach ($items as $qty) {
            $total += 10000 * $qty;
        }
        DB::table('orders')->insert([
            'id' => $id, 'workspace_id' => $site->workspace_id, 'site_id' => $site->id, 'external_id' => (string) random_int(1000, 99999999), 'contact_id' => $contactId,
            'status' => $paid ? 'completed' : 'pending', 'is_paid' => $paid, 'currency' => 'COP', 'total' => $total, 'total_ws' => $total,
            'created_at' => $at, 'updated_at' => $at, 'paid_at' => $paid ? $at : null,
        ]);
        foreach ($items as $pid => $qty) {
            $categories = DB::table('products')->where('site_id', $site->id)->where('external_id', (string) $pid)->value('category_ids') ?? '[]';
            DB::table('order_items')->insert([
                'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $site->workspace_id, 'order_id' => $id, 'external_product_id' => (string) $pid,
                'name' => 'P'.$pid, 'category_ids' => $categories, 'qty' => $qty, 'unit_price' => '10000', 'line_total' => (string) (10000 * $qty),
            ]);
        }

        return $id;
    }

    /** @param array<string, mixed> $attributes */
    public function commerceContact(array $attributes = []): Contact
    {
        return $this->commerceRun(fn () => Contact::query()->create($attributes + ['workspace_id' => $this->ws->id]));
    }
}
