<?php

namespace Tests\Feature\Strategies\Support;

use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Datos de prueba de las estrategias, el Inicio y el asistente (docs/diseno/rediseno-v2.md §4 a §6). */
trait StrategyFixtures
{
    public User $owner;

    public Workspace $ws;

    public ?Site $site = null;

    public function strategyWorld(bool $site = true, array $channels = ['email']): void
    {
        $this->owner = $this->userWith2fa();
        $this->ws = $this->workspaceFor($this->owner);
        $this->ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota'])->save();
        if ($site) {
            [$this->site] = $this->connectedSite($this->ws);
            $this->importDone();
        }
        foreach ($channels as $channel) {
            $this->channel($channel);
        }
    }

    public function importDone(): void
    {
        app(CurrentWorkspace::class)->run($this->ws, fn () => Site::query()->whereKey($this->site?->id)->update(['backfill_state' => json_encode(['status' => 'completed', 'entities' => []])]));
    }

    public function channel(string $channel, string $status = 'active', ?string $workspaceId = null): string
    {
        $id = strtoupper((string) Str::ulid());
        DB::table('channel_accounts')->insert([
            'id' => $id, 'workspace_id' => $workspaceId ?? $this->ws->id, 'channel' => $channel,
            'provider' => match ($channel) {
                'whatsapp' => 'meta_cloud', 'email' => 'ses', 'messenger' => 'meta_messenger', 'instagram' => 'meta_instagram', default => 'twilio',
            },
            'display_name' => $channel, 'identity' => $channel === 'whatsapp' ? '+57 300 1234567' : null,
            'status' => $status, 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Una plantilla de WhatsApp con su par de Meta en ese estado (APPROVED, REJECTED…). */
    public function waTemplate(string $libraryKey, string $metaStatus = 'APPROVED', string $category = 'marketing'): void
    {
        $account = (string) DB::table('channel_accounts')->where('workspace_id', $this->ws->id)->where('channel', 'whatsapp')->value('id');
        $wa = strtoupper((string) Str::ulid());
        DB::table('wa_templates')->insert([
            'id' => $wa, 'workspace_id' => $this->ws->id, 'channel_account_id' => $account, 'name' => $libraryKey, 'language' => 'es',
            'category' => strtoupper($category), 'status' => $metaStatus, 'components' => json_encode([['type' => 'BODY', 'text' => 'Hola {{1}}, tu carrito te espera.']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('templates')->insert([
            'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'name' => $libraryKey,
            'category' => $category, 'library_key' => $libraryKey, 'wa_template_id' => $wa, 'status' => 'ready',
            'variables' => json_encode(['body' => ['1' => 'contacto.nombre']]), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function strategies(string $path = ''): string
    {
        return "/api/w/{$this->ws->publicId()}/strategies".$path;
    }

    /** @return array<string, mixed> la estrategia del catálogo */
    public function strategyIn(array $catalog, string $key): array
    {
        return collect($catalog['data'])->firstWhere('key', $key) ?? [];
    }

    public function inWs(callable $fn): mixed
    {
        return app(CurrentWorkspace::class)->run($this->ws, $fn);
    }
}
