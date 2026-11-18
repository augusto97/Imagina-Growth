<?php

declare(strict_types=1);

namespace App\Domain\Billing\Usage;

use App\Domain\Billing\Mail\UsageAlertMail;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos de uso al 80 % y al 100 % (M21) del volumen del plan (pedidos o contactos
 * activos) y de los correos incluidos: uno por umbral, métrica y periodo
 * (`usage_counters.alerts_sent`). Son correos de la plataforma al cliente (no salen
 * por los canales del cliente), con su marca (`Branding`).
 */
final class UsageAlerts
{
    public function __construct(private readonly PlanResolver $plans, private readonly Branding $branding) {}

    /** @return list<string> avisos enviados, p. ej. `orders:80` */
    public function check(Workspace $workspace, string $period): array
    {
        $plan = $this->plans->planFor($workspace);
        if ($plan === null) {
            return [];
        }
        $row = DB::table('usage_counters')->where('workspace_id', $workspace->id)->where('period', $period)->first();
        if ($row === null) {
            return [];
        }
        $sent = json_decode((string) ($row->alerts_sent ?? '[]'), true);
        $sent = is_array($sent) ? $sent : [];
        $new = [];
        foreach ([$plan->metric, 'emails'] as $metric) {
            $limit = $this->plans->limit($workspace, $metric);
            if ($limit === null || $limit <= 0) {
                continue;
            }
            $value = (int) ($row->{$metric} ?? 0);
            foreach ((array) config('igr-plans.alert_thresholds', [80, 100]) as $threshold) {
                $key = $metric.':'.(int) $threshold;
                if ($value * 100 < $limit * (int) $threshold || isset($sent[$key])) {
                    continue;
                }
                $sent[$key] = now()->toIso8601String();
                $new[] = $key;
                $this->notify($workspace, $metric, $value, $limit, (int) $threshold);
            }
        }
        if ($new !== []) {
            DB::table('usage_counters')->where('workspace_id', $workspace->id)->where('period', $period)->update(['alerts_sent' => json_encode($sent)]);
        }

        return $new;
    }

    private function notify(Workspace $workspace, string $metric, int $value, int $limit, int $threshold): void
    {
        $account = $workspace->account()->first();
        if ($account === null) {
            return;
        }
        $brand = $this->branding->for($workspace);
        foreach (BillingRecipients::for($account, $workspace) as $to) {
            try {
                Mail::to($to)->send(new UsageAlertMail($workspace->name, $metric, $value, $limit, $threshold, $brand, self::url($workspace)));
            } catch (Throwable $e) {
                Log::warning('igr.billing.alert_failed', ['workspace' => $workspace->id, 'metric' => $metric, 'error' => class_basename($e)]);
            }
        }
    }

    public static function url(Workspace $workspace): string
    {
        return rtrim((string) config('app.url'), '/').'/w/'.$workspace->publicId().'/facturacion';
    }
}
