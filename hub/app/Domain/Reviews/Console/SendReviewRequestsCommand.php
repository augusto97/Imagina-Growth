<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Console;

use App\Domain\Reviews\Actions\SendReviewRequests;
use App\Domain\Surveys\Actions\SendSurveyRequests;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class SendReviewRequestsCommand extends Command
{
    protected $signature = 'igr:reviews:send {--at= : Momento a evaluar (ISO 8601), para pruebas}';

    protected $description = 'Envía las solicitudes de reseña y las encuestas NPS/CSAT que ya tocan (cada 5 minutos)';

    public function handle(SendReviewRequests $reviews, SendSurveyRequests $surveys): int
    {
        $at = $this->option('at');
        $now = is_string($at) && $at !== '' ? CarbonImmutable::parse($at) : null;
        $r = $reviews->handle($now);
        $s = $surveys->handle($now);
        if ($this->output->isVerbose()) {
            $this->info(sprintf('Reseñas: %d enviadas · %d aplazadas · %d omitidas · %d vencidas', $r['sent'], $r['deferred'], $r['skipped'], $r['expired']));
            $this->info(sprintf('Encuestas: %d enviadas · %d aplazadas · %d omitidas · %d vencidas', $s['sent'], $s['deferred'], $s['skipped'], $s['expired']));
        }

        return self::SUCCESS;
    }
}
