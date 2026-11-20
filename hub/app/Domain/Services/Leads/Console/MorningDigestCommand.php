<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Console;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Services\Leads\Assistant\MorningDigest;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Resumen matutino de «Hoy» a cada vendedor (M18). Corre cada hora: a cada espacio de
 * trabajo con el resumen encendido le toca desde las 7:00 de su zona, una vez al día.
 */
final class MorningDigestCommand extends Command
{
    protected $signature = 'igr:leads:morning-digest {--now= : fecha y hora ISO para pruebas}';

    protected $description = 'Envía a cada vendedor el resumen de su lista «Hoy» (si está activado en Ajustes de leads).';

    public function handle(MorningDigest $digest, Entitlements $entitlements): int
    {
        $now = is_string($this->option('now')) ? CarbonImmutable::parse($this->option('now')) : CarbonImmutable::now();
        $ids = DB::table('lead_settings')->whereRaw("JSON_EXTRACT(assistant, '$.digest') = true")->pluck('workspace_id')->all();
        $sent = 0;
        foreach (Workspace::query()->whereIn('id', $ids)->get() as $workspace) {
            if (! $entitlements->allows($workspace, 'services')) {
                continue;
            }
            try {
                $sent += $digest->handle($workspace, $now);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("Resúmenes enviados: {$sent}");

        return self::SUCCESS;
    }
}
