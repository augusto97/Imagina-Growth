<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Queries;

use App\Domain\Onboarding\Models\WorkspaceOnboarding;
use App\Domain\Onboarding\Support\Verticals;
use App\Domain\Strategies\Queries\StrategyCatalog;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyPotential;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * El estado del asistente de configuración (docs/diseno/rediseno-v2.md §4). Los pasos hechos salen de los datos
 * reales, no de lo que la persona marcó: el negocio (el tipo elegido), la web conectada, el historial importado (con
 * lo que ya se aprendió mientras tanto), un canal conectado y al menos una estrategia prendida. Del espacio de trabajo
 * se guarda el tipo de negocio, el paso donde quedó y si lo saltó o lo terminó; de cada persona, si ya vio la
 * bienvenida.
 */
final class OnboardingState
{
    /** Pasos del asistente; `done` es la pantalla final («qué va a pasar ahora»). */
    public const STEPS = WorkspaceOnboarding::STEPS;

    public function __construct(private readonly StrategyCatalog $catalog, private readonly StrategyPotential $potential) {}

    /** @return array<string, mixed> */
    public function handle(Workspace $workspace, ?User $user): array
    {
        $ctx = StrategyContext::for($workspace);
        $row = $this->row($ctx);
        $steps = $this->steps($ctx, $row, true);
        $active = $this->activeStrategies($ctx);

        return [
            'status' => self::status($row),
            'business_type' => $row?->business_type,
            'suggested_business_type' => $ctx->detectedBusinessType(),
            'vertical' => Verticals::valid(is_string($v = $workspace->getAttribute('vertical')) ? $v : null) ? $v : null,
            'suggested_vertical' => $ctx->vertical(),
            'verticals' => Verticals::options(),
            'current_step' => $this->current($row, $steps),
            'steps' => $steps,
            'progress' => self::progress($steps),
            'welcome_seen' => self::welcomeSeen($workspace, $user),
            'skipped_at' => $row?->skipped_at?->toIso8601String(),
            'completed_at' => $row?->completed_at?->toIso8601String(),
            'workspace' => ['name' => $workspace->name, 'country' => $workspace->country, 'currency' => $workspace->currency, 'timezone' => $workspace->timezone],
            'recommended' => $this->recommended($ctx),
            'timeline' => $this->timeline($ctx, $active),
        ];
    }

    /**
     * La tarjeta de progreso del Inicio: se ve mientras el asistente no termine (también si lo saltó: nada bloquea
     * explorar, pero el progreso sigue a la vista). Con `user`, si esa persona ya vio la bienvenida (el Inicio la
     * lleva a verla la primera vez).
     *
     * @return array{status: string, visible: bool, current_step: string, completed: int, total: int, welcome_seen: bool}
     */
    public function summary(Workspace $workspace, ?StrategyContext $ctx = null, ?User $user = null): array
    {
        $ctx ??= StrategyContext::for($workspace);
        $row = $this->row($ctx);
        $steps = $this->steps($ctx, $row, false);
        $progress = self::progress($steps);

        return [
            'status' => self::status($row),
            'visible' => $row?->completed_at === null,
            'current_step' => $this->current($row, $steps),
        ] + $progress + ['welcome_seen' => self::welcomeSeen($workspace, $user)];
    }

    /** ¿La persona ya vio la bienvenida en este espacio de trabajo? (sin persona, se da por vista) */
    public static function welcomeSeen(Workspace $workspace, ?User $user): bool
    {
        return $user === null || DB::table('onboarding_user_states')->where('workspace_id', $workspace->id)->where('user_id', $user->id)->whereNotNull('welcome_seen_at')->exists();
    }

    /**
     * Las estrategias que propone el paso 5, con su estado y vista previa (incluye las que ya están prendidas).
     *
     * @return list<array<string, mixed>>
     */
    public function recommended(StrategyContext $ctx): array
    {
        $potentials = $this->potential->forWorkspace($ctx->workspace);
        $results = $this->catalog->results($ctx)['strategies'];
        $out = [];
        foreach ($this->catalog->recommended($ctx, 3, true) as $key) {
            $definition = StrategyRegistry::find($key);
            if ($definition === null) {
                continue;
            }
            $out[] = $this->catalog->present($definition, $ctx, $potentials, $results, true) + [
                'steps' => $this->catalog->texts($key, $ctx)['steps'],
                'preview' => $definition->primary()->preview($ctx),
            ];
        }

        return $out;
    }

    private function row(StrategyContext $ctx): ?WorkspaceOnboarding
    {
        return $ctx->remember('onboarding_row', fn () => WorkspaceOnboarding::query()->first());
    }

    public static function status(?WorkspaceOnboarding $row): string
    {
        return match (true) {
            $row?->completed_at !== null => 'completed',
            $row?->skipped_at !== null => 'skipped',
            $row !== null => 'in_progress',
            default => 'not_started',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function steps(StrategyContext $ctx, ?WorkspaceOnboarding $row, bool $details): array
    {
        $import = $ctx->import();
        $channels = $ctx->channels();
        $active = $this->activeStrategies($ctx);
        $steps = [
            ['key' => 'business', 'done' => $row?->business_type !== null || Verticals::valid(is_string($v = $ctx->workspace->getAttribute('vertical')) ? $v : null)],
            ['key' => 'connect', 'done' => $ctx->hasSite(), 'site_id' => ($id = $ctx->siteId() ?? $ctx->pendingSiteId()) !== null ? 'st_'.$id : null, 'pending' => $ctx->pendingSiteId() !== null],
            ['key' => 'history', 'done' => $ctx->historyImported(), 'status' => $import['status'], 'progress' => $import['progress'],
                'site_id' => $import['site_id'] !== null ? 'st_'.$import['site_id'] : null] + ($details ? ['learned' => $this->learned($ctx)] : []),
            ['key' => 'channels', 'done' => $ctx->hasChannel('whatsapp', 'email'), 'channels' => [
                'whatsapp' => $channels['whatsapp'] ?? null, 'email' => $channels['email'] ?? null,
            ]],
            ['key' => 'strategies', 'done' => $active !== [], 'active' => $active],
            ['key' => 'done', 'done' => $row?->completed_at !== null],
        ];

        return $steps;
    }

    /**
     * Las estrategias prendidas (sin las que el módulo trae prendidas por defecto, salvo que la persona las activara).
     *
     * @return list<string>
     */
    private function activeStrategies(StrategyContext $ctx): array
    {
        return $ctx->remember('onboarding_active', function () use ($ctx): array {
            $out = [];
            foreach (StrategyRegistry::all() as $definition) {
                if (in_array($definition->key, StrategyRegistry::ON_BY_DEFAULT, true) && $ctx->row($definition->key)?->status !== 'active') {
                    continue;
                }
                if (in_array($this->catalog->evaluate($definition, $ctx)['status'], ['active', 'attention'], true)) {
                    $out[] = $definition->key;
                }
            }

            return $out;
        });
    }

    /** @param list<array<string, mixed>> $steps */
    private function current(?WorkspaceOnboarding $row, array $steps): string
    {
        if ($row?->completed_at !== null) {
            return 'done';
        }
        if (is_string($row?->current_step) && in_array($row->current_step, self::STEPS, true)) {
            return $row->current_step;
        }
        foreach ($steps as $step) {
            if (! $step['done'] && $step['key'] !== 'done') {
                return (string) $step['key'];
            }
        }

        return 'done';
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{completed: int, total: int}
     */
    private static function progress(array $steps): array
    {
        $work = array_values(array_filter($steps, fn (array $s) => $s['key'] !== 'done'));

        return ['completed' => count(array_filter($work, fn (array $s) => $s['done'])), 'total' => count($work)];
    }

    /**
     * Lo que ya se aprendió del historial mientras se importa (sin datos personales). Tienda: clientes, pedidos
     * pagados, ventas y los productos que más se venden. Para todos (y lo que muestra un negocio sin tienda):
     * personas, contactos recibidos (leads), citas y de dónde llegan los contactos.
     *
     * @return array{customers: int, orders: int, revenue: string, top_products: list<string>, contacts: int, leads: int, appointments: int, sources: list<array{origin: string, label: string, count: int}>}|null
     */
    private function learned(StrategyContext $ctx): ?array
    {
        if (! $ctx->hasSite()) {
            return null;
        }
        $ws = $ctx->id();
        $store = $ctx->businessType() !== 'services';
        $orders = $store ? DB::table('orders')->where('workspace_id', $ws)->where('is_paid', true)->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_ws), 0) AS v')->first() : null;
        $top = $store ? array_values(DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $ws)->where('o.is_paid', true)->whereNotNull('i.name')
            ->groupBy('i.name')->orderByRaw('SUM(i.qty) DESC')->limit(3)->pluck('i.name')->map(fn ($n) => (string) $n)->all()) : [];
        $contacts = fn () => DB::table('contacts')->where('workspace_id', $ws)->whereNull('merged_into_id')->whereNull('deleted_at');
        $sources = [];
        if (! $store || $ctx->businessType() === 'both') {
            $rows = DB::table('leads')->where('workspace_id', $ws)->groupBy('origin')->selectRaw('origin, COUNT(*) AS n')->orderByDesc('n')->limit(3)->get();
            foreach ($rows as $row) {
                $origin = (string) ($row->origin ?? 'other');
                $label = __('home.lead_origins.'.$origin);
                $sources[] = ['origin' => $origin, 'label' => $label === 'home.lead_origins.'.$origin ? (string) __('home.lead_origins.other') : (string) $label, 'count' => (int) $row->n];
            }
        }

        return [
            'customers' => $store ? $contacts()->where('orders_count', '>', 0)->count() : 0,
            'orders' => (int) ($orders->n ?? 0),
            'revenue' => number_format((float) ($orders->v ?? 0), 2, '.', ''),
            'top_products' => $top,
            'contacts' => $contacts()->count(),
            'leads' => $sources !== [] ? (int) DB::table('leads')->where('workspace_id', $ws)->count() : 0,
            'appointments' => $store ? 0 : DB::table('appointments')->where('workspace_id', $ws)->count(),
            'sources' => $sources,
        ];
    }

    /**
     * «Qué va a pasar ahora» del último paso.
     *
     * @param  list<string>  $active
     * @return list<array{when: string, text: string}>
     */
    private function timeline(StrategyContext $ctx, array $active): array
    {
        $today = match (true) {
            ! $ctx->hasSite() => $active !== [] ? 'onboarding.timeline.today_no_site' : 'onboarding.timeline.today_no_site_off',
            default => $active !== [] ? 'onboarding.timeline.today' : 'onboarding.timeline.today_off',
        };

        return [
            ['when' => 'today', 'text' => __($today)],
            ['when' => 'week', 'text' => __($ctx->businessType() === 'services' ? 'onboarding.timeline.week_services' : 'onboarding.timeline.week')],
            ['when' => 'month', 'text' => __('onboarding.timeline.month')],
        ];
    }
}
