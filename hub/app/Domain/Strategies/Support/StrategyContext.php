<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Support;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Onboarding\Models\WorkspaceOnboarding;
use App\Domain\Onboarding\Support\Verticals;
use App\Domain\Strategies\Models\WorkspaceStrategy;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Queries\WorkspaceFeatures;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Lo que las estrategias necesitan saber del espacio de trabajo, leído una sola vez por petición (y de forma perezosa:
 * solo lo que pregunta alguna estrategia). Corre con el espacio de trabajo activo (CurrentWorkspace): los modelos con
 * BelongsToWorkspace ya vienen filtrados, y las consultas con DB::table filtran por `workspace_id` a mano.
 */
final class StrategyContext
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(public readonly Workspace $workspace) {}

    public static function for(Workspace $workspace): self
    {
        return new self($workspace);
    }

    /**
     * Memoriza un valor del contexto (también lo usan los bloques para sus propios datos).
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $key, Closure $compute): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $compute();
        }

        return $this->memo[$key];
    }

    /** Olvida lo leído (después de activar o pausar, para presentar el estado nuevo). */
    public function fresh(): self
    {
        return new self($this->workspace);
    }

    public function id(): string
    {
        return $this->workspace->id;
    }

    // ── Sitios ─────────────────────────────────────────────────────────────

    /** @return Collection<int, \stdClass> sitios no desconectados, del más viejo al más nuevo */
    public function sites(): Collection
    {
        return $this->remember('sites', fn () => DB::table('sites')->where('workspace_id', $this->id())->where('status', '!=', 'disconnected')
            ->orderBy('created_at')->get(['id', 'host', 'platform', 'status', 'backfill_state']));
    }

    /** @return Collection<int, \stdClass> */
    public function connectedSites(): Collection
    {
        return $this->sites()->whereIn('status', ['connected', 'degraded'])->values();
    }

    public function hasSite(): bool
    {
        return $this->connectedSites()->isNotEmpty();
    }

    public function hasStore(): bool
    {
        return $this->connectedSites()->contains('platform', 'woocommerce');
    }

    /** El primer sitio conectado (la tienda si se pide y existe). */
    public function siteId(bool $preferStore = false): ?string
    {
        $sites = $this->connectedSites();
        $site = $preferStore ? ($sites->firstWhere('platform', 'woocommerce') ?? $sites->first()) : $sites->first();

        return $site !== null ? (string) $site->id : null;
    }

    /** Un sitio creado que todavía no termina de conectarse (para llevar a su detalle). */
    public function pendingSiteId(): ?string
    {
        $site = $this->sites()->first();

        return $site !== null && ! $this->hasSite() ? (string) $site->id : null;
    }

    /** @return array{status: string|null, progress: float|null, site_id: string|null} la importación del sitio más avanzado */
    public function import(): array
    {
        return $this->remember('import', function (): array {
            $best = ['status' => null, 'progress' => null, 'site_id' => null];
            foreach ($this->connectedSites() as $site) {
                $state = is_string($site->backfill_state) ? (array) json_decode($site->backfill_state, true) : [];
                $status = is_string($state['status'] ?? null) ? $state['status'] : null;
                if ($status === null || $best['status'] === 'completed') {
                    continue;
                }
                $total = 0;
                $sent = 0;
                foreach ((array) ($state['entities'] ?? []) as $e) {
                    $total += (int) ($e['total'] ?? 0);
                    $sent += (int) ($e['sent'] ?? 0);
                }
                $best = ['status' => $status, 'progress' => $total > 0 ? round($sent / $total, 4) : null, 'site_id' => (string) $site->id];
            }

            return $best;
        });
    }

    /** El historial está importado (o no hay nada que importar: un sitio sin WooCommerce no tiene pedidos). */
    public function historyImported(): bool
    {
        if ($this->import()['status'] === 'completed') {
            return true;
        }

        return $this->hasSite() && ! $this->hasStore() && $this->import()['status'] === null;
    }

    // ── Canales ────────────────────────────────────────────────────────────

    /** @return array<string, string> canal → `active` (hay una cuenta activa) o `down` (solo cuentas caídas) */
    public function channels(): array
    {
        return $this->remember('channels', function (): array {
            $out = [];
            foreach (DB::table('channel_accounts')->where('workspace_id', $this->id())->get(['channel', 'status']) as $row) {
                $channel = (string) $row->channel;
                if ($row->status === 'active') {
                    $out[$channel] = 'active';
                } elseif (in_array($row->status, ['error', 'disconnected', 'paused'], true) && ($out[$channel] ?? null) !== 'active') {
                    $out[$channel] = 'down';
                }
            }

            return $out;
        });
    }

    public function hasChannel(string ...$channels): bool
    {
        foreach ($channels as $channel) {
            if (($this->channels()[$channel] ?? null) === 'active') {
                return true;
            }
        }

        return false;
    }

    public function channelDown(string $channel): bool
    {
        return ($this->channels()[$channel] ?? null) === 'down';
    }

    /**
     * El problema de un bloque prendido que usa esos canales: `channel_down` si uno quedó caído (Meta desconectó el
     * número, el correo falla), `no_channel` si ninguno está conectado; null si al menos uno funciona y ninguno cayó.
     *
     * @param  list<string>  $channels
     */
    public function channelProblem(array $channels): ?string
    {
        foreach ($channels as $channel) {
            if ($this->channelDown($channel)) {
                return 'channel_down';
            }
        }

        return $channels !== [] && ! $this->hasChannel(...$channels) ? 'no_channel' : null;
    }

    /** El número de la cuenta de WhatsApp activa, solo dígitos (para el botón de WhatsApp del sitio). */
    public function whatsappNumber(): ?string
    {
        return $this->remember('wa_number', function (): ?string {
            $identity = DB::table('channel_accounts')->where('workspace_id', $this->id())->where('channel', 'whatsapp')->where('status', 'active')
                ->orderByDesc('is_default')->orderBy('created_at')->value('identity');
            $digits = preg_replace('/\D+/', '', (string) $identity) ?? '';

            return strlen($digits) >= 8 ? $digits : null;
        });
    }

    /**
     * Estado de las plantillas de WhatsApp por `library_key`: el de Meta (`APPROVED`, `PENDING`, `REJECTED`…) de la
     * plantilla enlazada, `unlinked` si la plantilla existe pero no tiene la de Meta elegida, o null si no existe.
     *
     * @return array<string, string>
     */
    public function waTemplates(): array
    {
        return $this->remember('wa_templates', function (): array {
            $rows = DB::table('templates as t')->leftJoin('wa_templates as w', fn ($j) => $j->on('w.id', '=', 't.wa_template_id')->where('w.workspace_id', $this->id()))
                ->where('t.workspace_id', $this->id())->where('t.channel', 'whatsapp')->where('t.status', '!=', 'archived')->whereNotNull('t.library_key')
                ->orderBy('t.updated_at')->get(['t.library_key', 'w.status']);
            $out = [];
            foreach ($rows as $row) {
                $status = $row->status !== null ? strtoupper((string) $row->status) : 'unlinked';
                // Si hay varias con la misma clave, gana la aprobada.
                if (($out[(string) $row->library_key] ?? null) !== 'APPROVED') {
                    $out[(string) $row->library_key] = $status;
                }
            }

            return $out;
        });
    }

    public function waTemplateApproved(string $libraryKey): bool
    {
        return ($this->waTemplates()[$libraryKey] ?? null) === 'APPROVED';
    }

    // ── Flujos y widgets ───────────────────────────────────────────────────

    /** @return Collection<int, \stdClass> todos los flujos de la biblioteca (también los archivados) */
    public function libraryFlows(): Collection
    {
        return $this->remember('flows', fn () => DB::table('flows')->where('workspace_id', $this->id())->whereNotNull('template_key')
            ->orderBy('created_at')->get(['id', 'status', 'template_key', 'current_version_id']));
    }

    /** @return Collection<int, \stdClass> los flujos vivos (no archivados) de esa clave */
    public function flows(string $templateKey): Collection
    {
        return $this->libraryFlows()->where('template_key', $templateKey)->where('status', '!=', 'archived')->values();
    }

    /** @return Collection<int, \stdClass> widgets no archivados */
    public function widgets(): Collection
    {
        return $this->remember('widgets', fn () => DB::table('onsite_widgets')->where('workspace_id', $this->id())->where('status', '!=', 'archived')
            ->orderBy('created_at')->get(['id', 'kind', 'status', 'site_id']));
    }

    // ── Plan, IA y decisiones ──────────────────────────────────────────────

    public function module(string $module): bool
    {
        $this->remember('modules_loaded', function (): bool {
            try {
                // Pennant en una sola consulta para todos los módulos; después `allows()` lee de su memoria.
                Feature::for($this->workspace)->loadMissing(array_map(fn (string $m) => PlanResolver::feature($m), PlanCatalog::modules()));
            } catch (Throwable) {
                // Otro Entitlements (pruebas, sin Pennant): `allows()` responde igual, uno por uno.
            }

            return true;
        });

        return $this->remember('module:'.$module, fn () => app(Entitlements::class)->allows($this->workspace, $module));
    }

    public function aiAvailable(): bool
    {
        return $this->remember('ai', function (): bool {
            try {
                return app(AiGateway::class)->available($this->id());
            } catch (Throwable) {
                return false;
            }
        });
    }

    /** @return Collection<string, WorkspaceStrategy> */
    public function rows(): Collection
    {
        return $this->remember('rows', fn () => WorkspaceStrategy::query()->get()->keyBy('strategy_key'));
    }

    public function row(string $key): ?WorkspaceStrategy
    {
        return $this->rows()->get($key);
    }

    // ── Tipo de negocio ────────────────────────────────────────────────────

    /** Lo que eligió la persona en el asistente, o null. */
    public function declaredBusinessType(): ?string
    {
        return $this->remember('business_declared', fn () => WorkspaceOnboarding::query()->value('business_type'));
    }

    /** Lo que dicen los datos (WorkspaceFeatures): `store`, `services` o `both`; sin datos, `store`. */
    public function detectedBusinessType(): string
    {
        return $this->remember('business_detected', function (): string {
            $caps = app(WorkspaceFeatures::class)->for($this->workspace)['capabilities'];
            if ($caps['store'] === true && $caps['services'] === true) {
                return 'both';
            }

            return $caps['services'] === true ? 'services' : 'store';
        });
    }

    /** Lo elegido en el asistente; si no, lo que implica la vertical guardada; si no, lo que dicen los datos. */
    public function businessType(): string
    {
        $vertical = $this->workspace->getAttribute('vertical');

        return $this->declaredBusinessType()
            ?? (Verticals::valid(is_string($vertical) ? $vertical : null) ? Verticals::businessType((string) $vertical) : null)
            ?? $this->detectedBusinessType();
    }

    /**
     * A qué se dedica (docs/diseno/rediseno-v2.md §11.1): la guardada en el espacio de trabajo; si no, la que sugiere
     * su industria; si no, `store` para una tienda y `other` para servicios.
     */
    public function vertical(): string
    {
        return $this->remember('vertical', function (): string {
            $saved = $this->workspace->getAttribute('vertical');
            if (is_string($saved) && Verticals::valid($saved)) {
                return $saved;
            }
            $industry = $this->workspace->getAttribute('industry');
            $suggested = Verticals::fromIndustry(is_string($industry) ? $industry : null);
            $type = $this->declaredBusinessType() ?? $this->detectedBusinessType();
            if ($suggested !== null && ($type === 'both' || Verticals::businessType($suggested) === $type)) {
                return $suggested;
            }

            return $type === 'services' ? Verticals::OTHER : Verticals::STORE;
        });
    }

    /**
     * ¿La estrategia le sirve a este negocio? Por su tipo (tienda, servicios o los dos) y, si la estrategia es de
     * ciertas verticales, por la vertical del negocio (uno «tienda + servicios» también cuenta como tienda).
     */
    public function serves(StrategyDefinition $definition): bool
    {
        $type = $this->businessType();
        if ($type !== 'both' && ! in_array($type, $definition->businessTypes(), true)) {
            return false;
        }
        if ($definition->verticals === null) {
            return true;
        }
        $mine = $type === 'both' ? [$this->vertical(), Verticals::STORE] : [$this->vertical()];

        return array_intersect($mine, $definition->verticals) !== [];
    }

    /** Los textos van en su versión de servicios cuando el negocio solo vende servicios. */
    public function speaksServices(): bool
    {
        return $this->businessType() === 'services';
    }
}
