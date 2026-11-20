<?php

use App\Analytics\Ch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * R12 de la auditoría: las tareas que corren cada minuto o cada 5 minutos buscan en todos los espacios de trabajo
 * a la vez; sin estos índices recorrían la tabla completa.
 */

it('las consultas de las tareas programadas tienen su índice', function (string $table, string $index, string $sql) {
    expect(Schema::hasIndex($table, $index))->toBeTrue();
    // La consulta de la tarea sigue siendo válida con el índice (el plan con datos está en el informe de la auditoría).
    expect(DB::select('EXPLAIN '.$sql))->not->toBeEmpty();
})->with([
    ['carts', 'carts_status_activity', "SELECT DISTINCT workspace_id FROM carts WHERE status = 'active' AND items_count > 0 AND last_activity_at < NOW() AND last_activity_at >= NOW() - INTERVAL 72 HOUR"],
    ['messages', 'messages_status_deferred', "SELECT id FROM messages WHERE status = 'deferred' AND deferred_until <= NOW() ORDER BY deferred_until LIMIT 2000"],
    ['webhook_deliveries', 'webhook_deliveries_next_attempt', 'SELECT id FROM webhook_deliveries WHERE next_attempt_at IS NOT NULL AND next_attempt_at <= NOW() ORDER BY next_attempt_at LIMIT 500'],
    ['webhook_deliveries', 'webhook_deliveries_created', 'SELECT id FROM webhook_deliveries WHERE created_at < NOW() - INTERVAL 30 DAY LIMIT 5000'],
    ['review_requests', 'review_requests_due', "SELECT DISTINCT workspace_id FROM review_requests WHERE status = 'scheduled' AND scheduled_for <= NOW()"],
    ['survey_requests', 'survey_requests_due', "SELECT DISTINCT workspace_id FROM survey_requests WHERE status = 'scheduled' AND scheduled_for <= NOW()"],
    ['appointments', 'appointments_status_starts', "SELECT DISTINCT workspace_id FROM appointments WHERE status IN ('pending', 'confirmed') AND starts_at <= NOW() + INTERVAL 25 HOUR"],
]);

it('events tiene el índice de salto por received_at («eventos de las últimas 24 horas»)', function () {
    $this->useIngestInfrastructure();
    $rows = app(Ch::class)->selectWithParams("SELECT name, type FROM system.data_skipping_indices WHERE database = {db:String} AND table = 'events' AND name = 'idx_received_at'", ['db' => app(Ch::class)->database()]);
    expect($rows)->toBe([['name' => 'idx_received_at', 'type' => 'minmax']]);
});
