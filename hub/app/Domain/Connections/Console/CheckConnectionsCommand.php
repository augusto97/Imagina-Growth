<?php

declare(strict_types=1);

namespace App\Domain\Connections\Console;

use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\ProviderRegistry;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Connections\Support\ConnectionTokens;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;

/**
 * Revisa cada noche las conexiones activas: Google renueva el token (así un acceso revocado se ve en el panel antes
 * de que falle una conversión), Meta pasa por `debug_token` y TikTok lista sus anunciantes. Un acceso que ya no
 * sirve queda `expired` con el aviso; una falla pasajera solo deja `last_error`.
 */
final class CheckConnectionsCommand extends Command
{
    protected $signature = 'igr:connections:check {--workspace= : ID público de un espacio de trabajo}';

    protected $description = 'Comprueba que las conexiones con Meta, Google y TikTok sigan sirviendo';

    public function handle(ProviderRegistry $providers, ConnectionTokens $tokens, CurrentWorkspace $current): int
    {
        $query = OAuthConnection::withoutWorkspaceScope()->where('status', OAuthConnection::ACTIVE)->orderBy('id');
        if (is_string($this->option('workspace'))) {
            $query->where('workspace_id', (string) preg_replace('/^ws_/', '', $this->option('workspace')));
        }
        $ok = $expired = $failed = 0;
        foreach ($query->lazyById(100) as $connection) {
            $workspace = Workspace::query()->find($connection->workspace_id);
            if ($workspace === null) {
                continue;
            }
            $current->run($workspace, function () use ($connection, $providers, $tokens, &$ok, &$expired, &$failed): void {
                try {
                    $connection->provider === ConnectionProvider::Google ? $tokens->renew($connection) : $providers->for($connection->provider)->check($connection);
                    $connection->forceFill(['last_error' => null])->save();
                    $ok++;
                } catch (ConnectionError $e) {
                    if ($e->permanent) {
                        $tokens->markExpired($connection, $e->getMessage());
                        $expired++;
                    } else {
                        $connection->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
                        $failed++;
                    }
                } finally {
                    $connection->forceFill(['checked_at' => now()])->save();
                }
            });
        }
        $this->info("Conexiones al día: {$ok}; vencidas: {$expired}; con fallas pasajeras: {$failed}.");

        return self::SUCCESS;
    }
}
