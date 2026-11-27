<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Queries;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Platform\InstallationDoctor;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Onboarding\Queries\OnboardingState;
use App\Domain\Strategies\Queries\StrategyCatalog;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Strategies\Support\Requirement;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Queue\QueueActivity;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cómo está configurado un espacio de trabajo, sin IA y solo con hechos y claves (nunca secretos ni datos de
 * contactos): tipo de negocio, plan y módulos, la web (estado, tienda, importación del historial), cada canal
 * (WhatsApp oficial o no oficial y su estado, correo, SMS, Messenger, Instagram), anuncios, agenda, el asistente de
 * configuración, las estrategias prendidas, en pausa y recomendadas con lo que les falta, lo que falta en general
 * (`missing`, en orden de importancia) y señales de operación de la instalación que explican «no llegan avisos»
 * (último trabajo de cola, latido del programador, correo de la plataforma configurado).
 *
 * Lo usan «Pregúntale a la IA» (los próximos pasos según la cuenta) y la herramienta de lectura del servidor MCP.
 * Sale de las consultas del Inicio y de Estrategias (`StrategyContext`, `StrategyCatalog`, `OnboardingState`,
 * `WorkspaceFeatures`). Cada bloque que falla queda en null sin tumbar el resto.
 */
final class SetupStatus
{
    /** Lo que falta, del más importante al menos. */
    public const MISSING = ['site', 'channel', 'whatsapp_reconnect', 'setup', 'strategy', 'ads'];

    public function __construct(
        private readonly StrategyCatalog $catalog,
        private readonly OnboardingState $onboarding,
        private readonly WorkspaceFeatures $features,
        private readonly Entitlements $entitlements,
        private readonly CurrentWorkspace $current,
    ) {}

    /** @return array<string, mixed> */
    public static function for(Workspace $workspace): array
    {
        return app(self::class)->handle($workspace);
    }

    /**
     * Corre con ese espacio de trabajo como actual (las estrategias leen modelos con su alcance): sirve desde una
     * petición, un trabajo o la consola, y deja el contexto como estaba.
     *
     * @return array<string, mixed>
     */
    public function handle(Workspace $workspace): array
    {
        return $this->current->run($workspace, fn (): array => $this->read($workspace));
    }

    /** @return array<string, mixed> */
    private function read(Workspace $workspace): array
    {
        $ctx = StrategyContext::for($workspace);
        $status = [
            'workspace' => [
                'id' => $workspace->publicId(),
                'country' => $workspace->country,
                'currency' => $workspace->currency,
                'timezone' => $workspace->timezone,
                'business_type' => $this->safe(fn () => $ctx->businessType()),
                'vertical' => $this->safe(fn () => $ctx->vertical()),
            ],
            'plan' => $this->safe(fn () => ['key' => $this->entitlements->planFor($workspace), 'modules' => $this->features->for($workspace)['modules']]),
            'site' => $this->safe(fn () => $this->site($ctx)),
            'channels' => $this->safe(fn () => $this->channels($ctx)),
            'ads' => $this->safe(fn () => $this->ads($ctx)),
            'agenda' => $this->safe(fn () => $this->agenda($ctx)),
            'setup' => $this->safe(fn () => $this->setup($workspace, $ctx)),
            'strategies' => $this->safe(fn () => $this->strategies($ctx)),
            'operations' => self::operations(),
        ];
        $status['missing'] = self::missing($status);

        return $status;
    }

    /**
     * Señales de operación de toda la instalación (no son del cliente ni son secretas).
     *
     * @return array{last_job_at: string|null, scheduler_heartbeat_age_seconds: int|null, platform_mail_configured: bool}
     */
    public static function operations(): array
    {
        try {
            $heartbeat = Cache::get(InstallationDoctor::SCHEDULER_HEARTBEAT);
        } catch (Throwable) {
            $heartbeat = null;
        }

        return [
            'last_job_at' => QueueActivity::lastProcessedAt()?->toIso8601String(),
            'scheduler_heartbeat_age_seconds' => is_numeric($heartbeat) ? max(0, now()->getTimestamp() - (int) $heartbeat) : null,
            'platform_mail_configured' => ! in_array((string) config('mail.default'), ['log', 'array'], true),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function safe(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            Log::warning('igr.setup_status.block_failed', ['error' => class_basename($e)]);

            return null;
        }
    }

    /** @return array{state: string, store: bool, history_import: string} */
    private function site(StrategyContext $ctx): array
    {
        $state = match (true) {
            $ctx->hasSite() => 'connected',
            $ctx->pendingSiteId() !== null => 'pending',
            DB::table('sites')->where('workspace_id', $ctx->id())->where('status', 'disconnected')->exists() => 'disconnected',
            default => 'none',
        };
        $import = $ctx->import()['status'] ?? null;

        return [
            'state' => $state,
            'store' => $ctx->hasStore(),
            'history_import' => match (true) {
                ! $ctx->hasSite() => 'not_applicable',
                $import === null => 'none',
                in_array($import, ['completed', 'failed'], true) => $import,
                default => 'running',
            },
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function channels(StrategyContext $ctx): array
    {
        $channels = $ctx->channels();
        $providers = DB::table('channel_accounts')->where('workspace_id', $ctx->id())->where('channel', 'whatsapp')->where('status', 'active')
            ->pluck('provider')->map(fn ($p) => (string) $p)->unique()->values()->all();
        $official = array_values(array_diff($providers, ChannelAccount::UNOFFICIAL));
        $state = fn (string $channel): string => match ($channels[$channel] ?? null) {
            'active' => 'active',
            'down' => 'down',
            default => 'none',
        };

        return [
            'whatsapp' => [
                'state' => $state('whatsapp'),
                'kind' => $providers === [] ? null : ($official !== [] ? 'official' : 'unofficial'),
                'providers' => $providers,
            ],
            'email' => ['state' => $state('email')],
            'sms' => ['state' => $state('sms')],
            'messenger' => ['state' => $state('messenger')],
            'instagram' => ['state' => $state('instagram')],
        ];
    }

    /** @return array{meta: bool, google: bool, tiktok: bool} */
    private function ads(StrategyContext $ctx): array
    {
        $active = DB::table('ad_destinations')->where('workspace_id', $ctx->id())->where('status', 'active')
            ->pluck('platform')->map(fn ($p) => (string) $p)->all();

        return ['meta' => in_array('meta', $active, true), 'google' => in_array('google', $active, true), 'tiktok' => in_array('tiktok', $active, true)];
    }

    /** @return array{services: int, staff: int, calendars: int} */
    private function agenda(StrategyContext $ctx): array
    {
        $staff = DB::table('booking_staff')->where('workspace_id', $ctx->id())->where('active', true)
            ->selectRaw('COUNT(*) AS total, SUM(calendar IS NOT NULL) AS calendars')->first();

        return [
            'services' => DB::table('booking_services')->where('workspace_id', $ctx->id())->where('active', true)->count(),
            'staff' => (int) ($staff->total ?? 0),
            'calendars' => (int) ($staff->calendars ?? 0),
        ];
    }

    /** @return array{status: string, finished: bool, completed: int, total: int} */
    private function setup(Workspace $workspace, StrategyContext $ctx): array
    {
        $summary = $this->onboarding->summary($workspace, $ctx);

        return [
            'status' => (string) $summary['status'],
            'finished' => ! $summary['visible'],
            'completed' => (int) $summary['completed'],
            'total' => (int) $summary['total'],
        ];
    }

    /** @return array{active: list<string>, paused: list<string>, recommended: list<array{key: string, status: string, missing: list<string>}>} */
    private function strategies(StrategyContext $ctx): array
    {
        $active = [];
        $paused = [];
        foreach ($ctx->rows() as $row) {
            if (StrategyRegistry::find((string) $row->strategy_key) === null) {
                continue;
            }
            if ($row->status === 'active') {
                $active[] = (string) $row->strategy_key;
            } elseif ($row->status === 'paused') {
                $paused[] = (string) $row->strategy_key;
            }
        }
        $recommended = [];
        foreach ($this->catalog->suggestions($ctx, 3) as $key) {
            $definition = StrategyRegistry::find($key);
            if ($definition === null) {
                continue;
            }
            $evaluation = $this->catalog->evaluate($definition, $ctx);
            $missing = [];
            foreach ($evaluation['requirements'] as $requirement) {
                if ($requirement instanceof Requirement && $requirement->blocks()) {
                    $missing[] = $requirement->key;
                }
            }
            $recommended[] = ['key' => $key, 'status' => (string) $evaluation['status'], 'missing' => array_values(array_unique($missing))];
        }

        return ['active' => $active, 'paused' => $paused, 'recommended' => $recommended];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return list<string>
     */
    private static function missing(array $status): array
    {
        $out = [];
        if (in_array($status['site']['state'] ?? null, ['none', 'pending', 'disconnected'], true)) {
            $out[] = 'site';
        }
        $channels = $status['channels'] ?? null;
        if (is_array($channels)) {
            $whatsapp = $channels['whatsapp']['state'] ?? 'none';
            $any = array_filter(['whatsapp', 'email', 'sms'], fn (string $c) => ($channels[$c]['state'] ?? 'none') === 'active');
            if ($any === []) {
                $out[] = $whatsapp === 'down' ? 'whatsapp_reconnect' : 'channel';
            } elseif ($whatsapp === 'down') {
                $out[] = 'whatsapp_reconnect';
            }
        }
        if (($status['setup']['finished'] ?? true) === false) {
            $out[] = 'setup';
        }
        if (is_array($status['strategies'] ?? null) && $status['strategies']['active'] === []) {
            $out[] = 'strategy';
        }
        if (is_array($status['ads'] ?? null) && ! in_array(true, $status['ads'], true)) {
            $out[] = 'ads';
        }

        return $out;
    }
}
