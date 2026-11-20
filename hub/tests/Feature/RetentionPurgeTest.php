<?php

use App\Domain\Messaging\Actions\ArchiveOldMessages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * R14: purga nocturna por retención. Lo viejo se borra y lo reciente se queda; los pasos de un recorrido que sigue
 * vivo no se tocan aunque sean viejos.
 */

beforeEach(function () {
    $this->ws = $this->workspaceFor($this->userWith2fa());
    $id = fn () => strtoupper((string) Str::ulid());
    $ws = $this->ws->id;
    $old = now()->subDays(400);
    $recent = now()->subHours(2);

    DB::table('ingest_rejections')->insert([
        ['workspace_id' => $ws, 'reason' => 'vieja', 'created_at' => now()->subDays(8)],
        ['workspace_id' => $ws, 'reason' => 'reciente', 'created_at' => $recent],
    ]);
    DB::table('failed_jobs')->insert([
        ['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(31)],
        ['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'reciente', 'failed_at' => $recent],
    ]);
    DB::table('job_batches')->insert([
        ['id' => (string) Str::uuid(), 'name' => 'vieja', 'total_jobs' => 1, 'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'created_at' => now()->subDays(9)->getTimestamp(), 'finished_at' => now()->subDays(8)->getTimestamp()],
        ['id' => (string) Str::uuid(), 'name' => 'en curso', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'created_at' => now()->subDays(9)->getTimestamp(), 'finished_at' => null],
    ]);
    DB::table('site_connect_codes')->insert([
        ['id' => $id(), 'workspace_id' => $ws, 'code_hash' => hash('sha256', 'a'), 'expires_at' => now()->subDays(8), 'used_at' => null],
        ['id' => $id(), 'workspace_id' => $ws, 'code_hash' => hash('sha256', 'b'), 'expires_at' => now()->addMinutes(10), 'used_at' => null],
    ]);
    DB::table('workspace_invitations')->insert([
        ['id' => $id(), 'workspace_id' => $ws, 'email' => 'vencida@cliente.co', 'role' => 'admin', 'token_hash' => hash('sha256', '1'), 'expires_at' => now()->subDays(31), 'accepted_at' => null, 'created_at' => now()->subDays(38), 'updated_at' => now()],
        ['id' => $id(), 'workspace_id' => $ws, 'email' => 'aceptada@cliente.co', 'role' => 'admin', 'token_hash' => hash('sha256', '2'), 'expires_at' => now()->subDays(31), 'accepted_at' => now()->subDays(35), 'created_at' => now()->subDays(38), 'updated_at' => now()],
        ['id' => $id(), 'workspace_id' => $ws, 'email' => 'vigente@cliente.co', 'role' => 'admin', 'token_hash' => hash('sha256', '3'), 'expires_at' => now()->addDays(5), 'accepted_at' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('password_reset_tokens')->insert([
        ['email' => 'vieja@cliente.co', 'token' => 'x', 'created_at' => now()->subDays(2)],
        ['email' => 'nueva@cliente.co', 'token' => 'y', 'created_at' => now()->subMinutes(5)],
    ]);
    $run = fn (string $status) => tap($id(), fn (string $runId) => DB::table('flow_runs')->insert([
        'id' => $runId, 'workspace_id' => $ws, 'flow_id' => $id(), 'flow_version_id' => $id(), 'contact_id' => $id(), 'status' => $status,
        'started_at' => $old, 'trigger_key' => 'test:'.$runId, 'created_at' => $old, 'updated_at' => $old,
    ]));
    $this->doneRun = $run('completed');
    $this->liveRun = $run('waiting');
    foreach ([[$this->doneRun, $old], [$this->doneRun, $recent], [$this->liveRun, $old]] as $i => [$runId, $at]) {
        DB::table('flow_run_steps')->insert(['workspace_id' => $ws, 'run_id' => $runId, 'node_id' => 'n'.$i, 'attempt' => 1, 'outcome' => 'continue', 'executed_at' => $at]);
    }
    DB::table('ai_agent_turns')->insert([
        ['id' => $id(), 'workspace_id' => $ws, 'conversation_id' => $id(), 'mode' => 'auto', 'status' => 'replied', 'created_at' => $old, 'updated_at' => $old],
        ['id' => $id(), 'workspace_id' => $ws, 'conversation_id' => $id(), 'mode' => 'auto', 'status' => 'replied', 'created_at' => $recent, 'updated_at' => $recent],
    ]);
});

it('con --dry-run solo cuenta', function () {
    $this->artisan('igr:retention:purge', ['--dry-run' => true])->expectsOutput('flow_run_steps: 1')->assertSuccessful();
    expect(DB::table('flow_run_steps')->count())->toBe(3)->and(DB::table('failed_jobs')->count())->toBe(2);
});

it('borra lo vencido de cada tabla y deja lo reciente y lo que sigue en uso', function () {
    $this->artisan('igr:retention:purge')->assertSuccessful();

    expect(DB::table('ingest_rejections')->pluck('reason')->all())->toBe(['reciente'])
        ->and(DB::table('failed_jobs')->pluck('exception')->all())->toBe(['reciente'])
        ->and(DB::table('job_batches')->pluck('name')->all())->toBe(['en curso'])
        ->and(DB::table('site_connect_codes')->count())->toBe(1)
        ->and(DB::table('workspace_invitations')->orderBy('email')->pluck('email')->all())->toBe(['aceptada@cliente.co', 'vigente@cliente.co'])
        ->and(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['nueva@cliente.co'])
        ->and(DB::table('ai_agent_turns')->count())->toBe(1);
    // Del recorrido terminado se va el paso viejo; del que sigue esperando no se toca nada.
    expect(DB::table('flow_run_steps')->where('run_id', $this->doneRun)->count())->toBe(1)
        ->and(DB::table('flow_run_steps')->where('run_id', $this->liveRun)->count())->toBe(1);
});

it('las entregas de conversiones en ClickHouse vencen a los 90 días', function () {
    $this->useIngestInfrastructure();
    $rows = $this->chRows($this->ws, 'SELECT create_table_query AS q FROM system.tables WHERE database = currentDatabase() AND name = {t:String} AND {workspace_id:UInt32} > 0', ['t' => 'conversion_deliveries']);
    expect($rows[0]['q'] ?? '')->toContain('TTL')->toContain('toIntervalDay(90)');
});

/** Un mensaje para la retención, con su fecha de creación y de última actividad. */
function retentionMessage(string $workspaceId, array $attrs): string
{
    $id = (string) Str::ulid();
    $at = $attrs['created_at'] ?? now();
    DB::table('messages')->insert(array_replace([
        'id' => $id, 'workspace_id' => $workspaceId, 'contact_id' => (string) Str::ulid(), 'channel' => 'whatsapp', 'direction' => 'out', 'kind' => 'marketing',
        'source_type' => 'flow', 'status' => 'delivered', 'sent_at' => $at, 'delivered_at' => $at, 'cost_estimated' => 0.0125, 'cost_currency' => 'USD',
        'payload' => 'cifrado', 'created_at' => $at, 'updated_at' => $at,
    ], $attrs));

    return $id;
}

it('R14: los mensajes de más de 24 meses pasan al archivo agregado y se borran con lo que depende de ellos', function () {
    $ws = $this->ws->id;
    $old = now()->subDays(800)->startOfDay()->addHours(15);
    $flow = (string) Str::ulid();
    $campaign = (string) Str::ulid();
    $wa = retentionMessage($ws, ['flow_id' => $flow, 'created_at' => $old, 'clicked_at' => $old]);
    retentionMessage($ws, ['flow_id' => $flow, 'created_at' => $old->copy()->addHour(), 'status' => 'sent', 'delivered_at' => null]);   // sin entregar: Meta no cobra
    retentionMessage($ws, ['channel' => 'email', 'source_type' => 'campaign', 'campaign_id' => $campaign, 'created_at' => $old, 'status' => 'bounced', 'cost_estimated' => 0.0001]);
    retentionMessage($ws, ['created_at' => $old, 'status' => 'denied', 'sent_at' => null, 'delivered_at' => null, 'cost_estimated' => null]);   // nunca salió: no se archiva
    retentionMessage($ws, ['direction' => 'in', 'source_type' => 'inbox', 'kind' => 'service', 'created_at' => $old, 'status' => 'received', 'cost_estimated' => null]);
    // Se queda: reciente, o viejo con actividad dentro del margen de la atribución (un clic tardío en un enlace viejo).
    $recent = retentionMessage($ws, ['flow_id' => $flow, 'created_at' => now()->subDays(10)]);
    $active = retentionMessage($ws, ['flow_id' => $flow, 'created_at' => $old, 'clicked_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
    DB::table('tracked_links')->insert([
        ['code' => 'abcd1234', 'workspace_id' => $ws, 'message_id' => $wa, 'contact_id' => null, 'url' => 'https://tienda.co/carrito', 'created_at' => $old],
        ['code' => 'efgh5678', 'workspace_id' => $ws, 'message_id' => $recent, 'contact_id' => null, 'url' => 'https://tienda.co/', 'created_at' => now()],
    ]);
    DB::table('message_attachments')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $ws, 'message_id' => $wa, 'kind' => 'image', 'created_at' => $old]);

    $this->artisan('igr:retention:purge', ['--dry-run' => true])->expectsOutput('messages: 5')->assertSuccessful();
    expect(DB::table('messages')->count())->toBe(7)->and(DB::table('message_archive_daily')->count())->toBe(0);

    $this->artisan('igr:retention:purge')->expectsOutput('messages: 5')->expectsOutput('tracked_links: 1')->expectsOutput('message_attachments: 1')->assertSuccessful();
    expect(DB::table('messages')->pluck('id')->all())->toEqualCanonicalizing([$recent, $active])
        ->and(DB::table('tracked_links')->pluck('code')->all())->toBe(['efgh5678'])
        ->and(DB::table('message_attachments')->count())->toBe(0);
    $archive = DB::table('message_archive_daily')->where('workspace_id', $ws)->get()->keyBy(fn ($r) => $r->channel.':'.$r->direction);
    expect($archive)->toHaveCount(3)
        ->and($archive['whatsapp:out'])->toMatchObject(['date' => $old->toDateString(), 'kind' => 'marketing', 'source_type' => 'flow', 'flow_id' => $flow, 'campaign_id' => '',
            'total' => 2, 'sent' => 2, 'delivered' => 1, 'clicked' => 1])
        ->and((float) $archive['whatsapp:out']->cost_usd)->toBe(0.0125)
        ->and($archive['email:out'])->toMatchObject(['campaign_id' => $campaign, 'total' => 1, 'sent' => 1, 'bounced' => 1])
        ->and((float) $archive['email:out']->cost_usd)->toBe(0.0001)
        ->and($archive['whatsapp:in'])->toMatchObject(['source_type' => 'inbox', 'total' => 1]);

    // Otra corrida no suma dos veces.
    $this->artisan('igr:retention:purge')->expectsOutput('messages: 0')->assertSuccessful();
    expect((int) DB::table('message_archive_daily')->sum('total'))->toBe(4);
});

it('R14: el plazo de los mensajes nunca baja de lo que necesita la atribución', function () {
    config(['igr.retention.messages' => 30]);
    // La ventana de clic más larga es de 45 días: se protegen 45 + 60 = 105.
    $this->ws->forceFill(['settings' => array_replace_recursive($this->ws->settings ?? [], ['attribution' => ['click_days' => 45]])])->save();
    expect(ArchiveOldMessages::retentionDays())->toBe(105);
    $kept = retentionMessage($this->ws->id, ['created_at' => now()->subDays(100)]);
    retentionMessage($this->ws->id, ['created_at' => now()->subDays(110)]);
    $this->artisan('igr:retention:purge')->assertSuccessful();
    expect(DB::table('messages')->pluck('id')->all())->toBe([$kept]);
    config(['igr.retention.messages' => 730]);
    expect(ArchiveOldMessages::retentionDays())->toBe(730);
});
