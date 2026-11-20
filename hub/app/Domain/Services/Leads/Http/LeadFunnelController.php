<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Http;

use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Queries\LeadFunnel;
use App\Domain\Services\Leads\Support\FunnelNarrative;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * El embudo de leads del lunes en el tablero de leads (M18): la semana pasada completa
 * (o la actual, o la que empieza en una fecha) frente a la anterior, con la conclusión
 * escrita con IA (o sin IA) y solo con cifras de los hechos. El rol agente ve el suyo.
 */
final class LeadFunnelController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function __invoke(Request $request, LeadFunnel $funnel, FunnelNarrative $narrative): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $data = $request->validate(['pipeline' => ['nullable', 'string', 'max:40'], 'week' => ['nullable', 'string', 'max:10', 'regex:/^(last|current|\d{4}-\d{2}-\d{2})$/']]);
        $ws = $this->current->get();
        $pipeline = isset($data['pipeline']) ? (LeadPipeline::findByPublicId($data['pipeline']) ?? abort(404)) : Pipelines::default($ws->id);
        $start = self::weekStart((string) ($ws->timezone ?: 'America/Bogota'), $data['week'] ?? 'last');
        $owner = Gate::allows('ws.viewAllContacts') ? null : (string) $request->user()?->getAuthIdentifier();
        $result = $funnel->week($ws->id, $pipeline, $start, $owner, LeadSettings::for($ws->id)->assistantRules()['stale_days']);
        // La conclusión se guarda 6 h por hechos: mientras no cambien las cifras, no se vuelve a llamar a la IA.
        $key = RedisKey::for($ws->id, 'leads:funnel:'.sha1((string) json_encode($result)));
        $conclusion = Cache::get($key);
        if (! is_array($conclusion)) {
            $written = $narrative->write($ws->id, $result, (string) $ws->currency);
            $conclusion = ['text' => $written['text'], 'source' => $written['source']];
            if ($written['source'] === 'ai') {
                Cache::put($key, $conclusion, now()->addHours(6));
            }
        }

        return response()->json($result + ['conclusion' => $conclusion, 'currency' => $ws->currency, 'week_param' => $data['week'] ?? 'last']);
    }

    /** Lunes de la semana pedida, a medianoche en la zona del negocio. */
    public static function weekStart(string $tz, string $week): CarbonImmutable
    {
        $now = CarbonImmutable::now($tz);

        return match (true) {
            $week === 'current' => $now->startOfWeek(CarbonImmutable::MONDAY),
            $week === 'last' => $now->startOfWeek(CarbonImmutable::MONDAY)->subWeek(),
            default => CarbonImmutable::parse($week, $tz)->startOfWeek(CarbonImmutable::MONDAY),
        };
    }
}
