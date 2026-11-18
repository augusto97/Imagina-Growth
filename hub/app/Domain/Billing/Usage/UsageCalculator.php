<?php

declare(strict_types=1);

namespace App\Domain\Billing\Usage;

use App\Analytics\Ch;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Uso de un espacio de trabajo en un periodo (mes calendario en su zona horaria),
 * calculado desde las tablas de origen (M21):
 * - `orders`: pedidos creados en el mes.
 * - `contacts`: contactos activos (vistos en el sitio, con un pedido o con un mensaje
 *   entrante o saliente en el mes; sin borrados ni fusionados).
 * - `events`: eventos de ClickHouse del mes.
 * - `emails`, `wa_marketing`, `wa_utility` (utilidad y autenticación), `wa_service`
 *   (servicio y transaccional) y `sms`: mensajes salientes enviados en el mes.
 * - `ai_tokens`: tokens de entrada y salida de `ai_usage`.
 * `api_requests` no se calcula aquí: lo suma el medidor de la API.
 */
final class UsageCalculator
{
    public const METRICS = ['contacts', 'orders', 'events', 'emails', 'wa_marketing', 'wa_utility', 'wa_service', 'sms', 'ai_tokens'];

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} inicio y fin (UTC) del mes del periodo */
    public static function bounds(Workspace $workspace, string $period): array
    {
        $tz = $workspace->timezone ?: 'America/Bogota';
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00', $tz);
        assert($start instanceof CarbonImmutable);

        return [$start->utc(), $start->addMonth()->utc()];
    }

    public static function currentPeriod(Workspace $workspace, ?CarbonImmutable $at = null): string
    {
        return ($at ?? CarbonImmutable::now())->setTimezone($workspace->timezone ?: 'America/Bogota')->format('Y-m');
    }

    /** @return array<string, int> */
    public function compute(Workspace $workspace, string $period): array
    {
        [$from, $to] = self::bounds($workspace, $period);
        $ws = $workspace->id;
        $orders = DB::table('orders')->where('workspace_id', $ws)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();

        $contacts = DB::table('contacts as c')->where('c.workspace_id', $ws)->whereNull('c.deleted_at')->whereNull('c.merged_into_id')
            ->where(function ($q) use ($from, $to, $ws): void {
                $q->whereBetween('c.last_seen_at', [$from, $to])
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('orders as o')->whereColumn('o.contact_id', 'c.id')->where('o.workspace_id', $ws)->where('o.created_at', '>=', $from)->where('o.created_at', '<', $to))
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('messages as m')->whereColumn('m.contact_id', 'c.id')->where('m.workspace_id', $ws)->where('m.created_at', '>=', $from)->where('m.created_at', '<', $to));
            })->count();

        $messages = DB::table('messages')->where('workspace_id', $ws)->where('direction', 'out')->whereNotNull('sent_at')
            ->where('sent_at', '>=', $from)->where('sent_at', '<', $to)
            ->selectRaw('channel, kind, COUNT(*) AS n')->groupBy('channel', 'kind')->get();
        $out = ['emails' => 0, 'wa_marketing' => 0, 'wa_utility' => 0, 'wa_service' => 0, 'sms' => 0];
        foreach ($messages as $row) {
            $n = (int) $row->n;
            $key = match ((string) $row->channel) {
                'email' => 'emails',
                'sms' => 'sms',
                'whatsapp' => match ((string) $row->kind) {
                    'marketing' => 'wa_marketing',
                    'utility', 'authentication' => 'wa_utility',
                    default => 'wa_service',
                },
                default => null,
            };
            if ($key !== null) {
                $out[$key] += $n;
            }
        }

        $tokens = (int) DB::table('ai_usage')->where('workspace_id', $ws)
            ->where('date', '>=', $from->setTimezone($workspace->timezone ?: 'America/Bogota')->toDateString())
            ->where('date', '<', $to->setTimezone($workspace->timezone ?: 'America/Bogota')->toDateString())
            ->sum(DB::raw('tokens_in + tokens_out'));

        return ['contacts' => $contacts, 'orders' => $orders, 'events' => $this->events($workspace, $from, $to)] + $out + ['ai_tokens' => $tokens];
    }

    private function events(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): int
    {
        try {
            return (int) Ch::forWorkspace($workspace)->value(
                'SELECT count() FROM events WHERE workspace_id = {workspace_id:UInt32} AND occurred_at >= parseDateTime64BestEffort({from:String}, 3) AND occurred_at < parseDateTime64BestEffort({to:String}, 3)',
                ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            );
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
