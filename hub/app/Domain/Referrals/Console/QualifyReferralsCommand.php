<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Console;

use App\Domain\Referrals\Actions\QualifyDueReferrals;
use App\Domain\Referrals\Models\AffiliateConversion;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Console\WorkspaceOption;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/** Califica referidos y aprueba comisiones que pasaron el periodo de devolución (cada hora). */
final class QualifyReferralsCommand extends Command
{
    protected $signature = 'igr:referrals:qualify
        {--workspace=* : Solo estos espacios de trabajo (ws_…)}
        {--at= : Momento a evaluar (ISO 8601), para pruebas y para reprocesar}';

    protected $description = 'Califica los referidos y aprueba las comisiones de afiliados que pasaron el periodo de devolución';

    public function handle(QualifyDueReferrals $qualify, CurrentWorkspace $current): int
    {
        try {
            $only = WorkspaceOption::resolve($this->option('workspace'), allWhenEmpty: false);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $at = $this->option('at');
        $now = is_string($at) && $at !== '' ? CarbonImmutable::parse($at) : CarbonImmutable::now();

        // Solo los espacios de trabajo con algo abierto (plataforma: sin scope, de forma explícita).
        $ids = Referral::withoutWorkspaceScope()->whereIn('status', [Referral::ORDERED, Referral::QUALIFIED])->distinct()->pluck('workspace_id')
            ->merge(AffiliateConversion::withoutWorkspaceScope()->where('status', 'pending')->distinct()->pluck('workspace_id'))
            ->unique()->values()->all();
        $workspaces = $only->isNotEmpty() ? $only->whereIn('id', $ids) : Workspace::query()->whereIn('id', $ids)->where('status', 'active')->get();

        $failed = false;
        foreach ($workspaces as $workspace) {
            try {
                $counts = $current->run($workspace, fn () => $qualify->handle($now));
                if ($this->output->isVerbose()) {
                    $this->info("{$workspace->publicId()}: calificados {$counts['qualified']} · recompensados {$counts['rewarded']} · comisiones aprobadas {$counts['approved']}");
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("{$workspace->publicId()}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
