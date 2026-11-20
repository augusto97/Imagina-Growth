<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use App\Domain\Intelligence\Ai\AnthropicAiGateway;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Support\Holidays\Holidays;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Aplica los ajustes del panel sobre `config()` (F17). El valor del panel manda; sin valor rige lo que traía la
 * configuración al arrancar el proceso (el .env, también con `config:cache`, o el valor por defecto).
 *
 * - **Al arrancar** cada proceso (cada petición de FrankenPHP, cada comando, cada `schedule:run` del programador).
 * - **En caliente** en los procesos largos: en cada petición (`RefreshPlatformSettings`), antes de cada trabajo de las
 *   colas (Horizon) y cada pocos segundos en los workers de ingesta. Si la versión guardada cambió, vuelve a aplicar y
 *   olvida lo que se armó con la configuración anterior: los mailers, el cliente de la IA y los festivos en memoria.
 *
 * Nunca rompe el arranque: sin tabla (instalación, migraciones), sin base o sin caché, rige el .env.
 */
final class PlatformSettingsRuntime
{
    /** Comandos que no deben ver los ajustes del panel: `config:cache` guardaría los secretos descifrados en disco. */
    private const SKIP_COMMANDS = ['config:cache', 'optimize', 'package:discover', 'key:generate'];

    /** @var array<string, mixed>|null valores de config() antes del panel, por clave */
    private ?array $baseline = null;

    private ?string $applied = null;

    /** @var array<string, mixed> valores del panel aplicados ahora en este proceso */
    private array $current = [];

    private float $checkedAt = 0.0;

    public function __construct(private readonly Application $app, private readonly PlatformSettings $store) {}

    /** Al arrancar el proceso. */
    public function boot(): void
    {
        if ($this->skipsBoot()) {
            return;
        }
        $this->refresh();
    }

    /**
     * Si los ajustes guardados cambiaron desde la última vez, los vuelve a aplicar. Devuelve si aplicó algo.
     */
    public function refreshIfStale(): bool
    {
        if ($this->skipsBoot()) {
            return false;
        }
        $this->checkedAt = microtime(true);
        $version = $this->store->version();
        if ($version === null || $version === $this->applied) {
            return false;
        }

        return $this->refresh();
    }

    /** Lee los ajustes guardados y los aplica (aunque la versión no haya cambiado). */
    public function refresh(): bool
    {
        $this->checkedAt = microtime(true);
        $snapshot = $this->store->snapshot();
        if ($snapshot === null) {
            return false;
        }
        $initial = $this->applied === null;
        $this->apply($this->store->values($snapshot));
        $this->applied = $snapshot['version'];
        if (! $initial) {
            $this->forgetResolved();
        }

        return true;
    }

    /**
     * Escribe en config() el valor del panel de cada ajuste que cambió o, si se vació, el de antes del panel. Lo que no
     * cambió no se toca.
     *
     * @param  array<string, mixed>  $values
     */
    public function apply(array $values): void
    {
        $baseline = $this->baseline();
        $config = $this->app->make('config');
        $current = [];
        foreach (PlatformSettingsRegistry::all() as $key => $definition) {
            $new = SettingDefinition::filled($values[$key] ?? null) ? $values[$key] : null;
            if ($new !== null) {
                $current[$key] = $new;
            }
            if ($new === ($this->current[$key] ?? null)) {
                continue;
            }
            $writes = $new !== null ? $definition->configFor($new) : array_intersect_key($baseline, array_flip($definition->targets));
            foreach ($writes as $configKey => $configValue) {
                $config->set($configKey, $configValue);
            }
        }
        $this->current = $current;
    }

    /**
     * Lo que traía config() antes del panel (el .env o el valor por defecto), por clave de config.
     *
     * @return array<string, mixed>
     */
    public function baseline(): array
    {
        if ($this->baseline === null) {
            $config = $this->app->make('config');
            $this->baseline = [];
            foreach (PlatformSettingsRegistry::targets() as $key) {
                $this->baseline[$key] = $config->get($key);
            }
        }

        return $this->baseline;
    }

    /** El valor del ajuste sin el panel (para mostrar a qué vuelve si se vacía). */
    public function fallback(SettingDefinition $definition): mixed
    {
        return $definition->fromBaseline($this->baseline());
    }

    /** Cuándo se revisó por última vez la versión guardada (microtime). */
    public function checkedAt(): float
    {
        return $this->checkedAt;
    }

    /**
     * Vuelve a tomar como respaldo lo que diga config() ahora y olvida lo aplicado (las pruebas lo usan para simular
     * un proceso nuevo con otro .env).
     */
    public function rebase(): void
    {
        $this->baseline = null;
        $this->applied = null;
        $this->current = [];
    }

    /** Lo que se armó con la configuración anterior y la guarda en memoria. */
    private function forgetResolved(): void
    {
        Holidays::flush();
        if ($this->app->resolved('mail.manager')) {
            $mail = $this->app->make('mail.manager');
            if (is_object($mail) && method_exists($mail, 'forgetMailers')) {
                $mail->forgetMailers();
            }
        }
        // El cliente de Anthropic se crea una vez con la clave; un doble de pruebas (FakeAiGateway) se respeta.
        if ($this->app->resolved(AiGateway::class)) {
            try {
                if ($this->app->make(AiGateway::class) instanceof AnthropicAiGateway) {
                    $this->app->forgetInstance(AiGateway::class);
                }
            } catch (Throwable) {
                $this->app->forgetInstance(AiGateway::class);
            }
        }
    }

    private function skipsBoot(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }
        $argv = $_SERVER['argv'] ?? [];
        $command = is_array($argv) ? ($argv[1] ?? null) : null;

        return is_string($command) && in_array($command, self::SKIP_COMMANDS, true);
    }
}
