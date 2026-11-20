<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Messaging\Actions\ArchiveOldMessages;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Purga por retención (R14), cada noche. Borra por lotes (sin bloquear las tablas) lo que ya no sirve y puede llevar
 * datos personales o crecer sin fin. Los plazos van en `config/igr.php` (`retention`, en días):
 *
 * | Qué | Plazo |
 * |---|---|
 * | `ingest_rejections` (muestras de eventos rechazados) | 7 (`igr.ingest.rejection_retention_days`) |
 * | `failed_jobs` (trabajos fallidos, cifrados si llevan datos personales) | 30 |
 * | `job_batches` terminados | 7 |
 * | `site_connect_codes` vencidos o usados | 7 |
 * | `workspace_invitations` sin aceptar, después de vencer | 30 |
 * | `password_reset_tokens` (vencen a los 60 minutos) | 1 |
 * | `flow_run_steps` de recorridos terminados (cumplimiento.md §10: pasos de flujos, 6 meses) | 180 |
 * | `ai_agent_turns` (registro del agente de ventas, con textos) | 180 |
 *
 * | `messages` (cumplimiento.md §10: 24 meses), con sus `tracked_links` y `message_attachments`, después de sumarlos al
 *   archivo agregado `message_archive_daily` (ArchiveOldMessages; nunca menos de lo que protege la atribución) | 730 |
 *
 * Además: `webhook_deliveries` a los 30 días (`igr:webhooks:prune`) y `conversion_deliveries` de ClickHouse a los
 * 90 días (TTL de la tabla, `database/clickhouse/0305_conversion_deliveries_ttl.sql`). La auditoría y la evidencia de
 * consentimiento no se purgan aquí.
 */
final class RetentionPurgeCommand extends Command
{
    protected $signature = 'igr:retention:purge {--dry-run : Solo cuenta lo que borraría}';

    protected $description = 'Borra los registros viejos según los plazos de retención';

    private const BATCH = 5000;

    public function handle(ArchiveOldMessages $messages): int
    {
        $days = fn (string $key, int $default): int => max(1, (int) config("igr.retention.{$key}", $default));
        $now = now();
        $results = [
            'ingest_rejections' => $this->purge(DB::table('ingest_rejections')->where('created_at', '<', $now->copy()->subDays(max(1, (int) config('igr.ingest.rejection_retention_days', 7))))),
            'failed_jobs' => $this->purge(DB::table((string) config('queue.failed.table', 'failed_jobs'))->where('failed_at', '<', $now->copy()->subDays($days('failed_jobs', 30)))),
            'job_batches' => $this->purge(DB::table('job_batches')->whereNotNull('finished_at')->where('finished_at', '<', $now->copy()->subDays($days('job_batches', 7))->getTimestamp())),
            'site_connect_codes' => $this->purge(DB::table('site_connect_codes')->where(fn ($q) => $q->where('expires_at', '<', $now->copy()->subDays($days('connect_codes', 7)))
                ->orWhere('used_at', '<', $now->copy()->subDays($days('connect_codes', 7))))),
            'workspace_invitations' => $this->purge(DB::table('workspace_invitations')->whereNull('accepted_at')->where('expires_at', '<', $now->copy()->subDays($days('invitations', 30)))),
            'password_reset_tokens' => $this->purge(DB::table('password_reset_tokens')->where('created_at', '<', $now->copy()->subDays($days('password_resets', 1))), 'email'),
            'flow_run_steps' => $this->purge(DB::table('flow_run_steps')->where('executed_at', '<', $now->copy()->subDays($days('flow_run_steps', 180)))
                ->whereIn('run_id', DB::table('flow_runs')->select('id')->whereIn('status', ['completed', 'exited', 'failed']))),
            'ai_agent_turns' => $this->purge(DB::table('ai_agent_turns')->where('created_at', '<', $now->copy()->subDays($days('ai_agent_turns', 180)))),
        ];
        try {
            $results += $messages->handle((bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            report($e);
            $results['messages'] = null;
        }
        foreach ($results as $table => $count) {
            $this->line(sprintf('%s: %s', $table, $count === null ? __('no se pudo') : (string) $count));
        }

        return in_array(null, $results, true) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Borra (o cuenta, en --dry-run) por lotes de 5.000 filas.
     *
     * @param  Builder  $query
     */
    private function purge($query, string $key = 'id'): ?int
    {
        try {
            if ($this->option('dry-run')) {
                return (int) $query->count();
            }
            $total = 0;
            do {
                $ids = (clone $query)->limit(self::BATCH)->pluck($key)->all();
                $deleted = $ids === [] ? 0 : DB::table($query->from)->whereIn($key, $ids)->delete();
                $total += $deleted;
            } while ($deleted > 0 && count($ids) === self::BATCH);

            return $total;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
