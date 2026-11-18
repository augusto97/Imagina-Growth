<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot\Support;

use App\Domain\Campaigns\Http\CampaignPresenter;
use App\Domain\Campaigns\Support\CommercialCalendar;
use App\Domain\Segments\Models\Segment;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el copiloto puede usar del espacio de trabajo, sin datos personales:
 * segmentos con sus tamaños, plantillas (las de WhatsApp solo si Meta las
 * aprobó), diseños de correo de la biblioteca con sus textos editables, canales
 * conectados y el calendario comercial.
 */
final class CopilotCatalog
{
    /** Textos de un diseño de la biblioteca que el copiloto puede escribir. */
    public const COPY_SLOTS = ['subject', 'preheader', 'headline', 'intro', 'cta'];

    /** @return array<int, array<string, mixed>> */
    public function segments(int $limit = 80): array
    {
        return Segment::query()->orderByDesc('is_system')->orderByDesc('member_count')->limit($limit)->get()
            ->map(fn (Segment $s) => [
                'id' => $s->publicId(),
                'name' => $s->name,
                'members' => (int) $s->member_count,
                'reach' => array_map('intval', array_intersect_key((array) $s->channel_counts, array_flip(['email', 'whatsapp', 'sms']))),
                'system' => (bool) $s->is_system,
                'system_key' => $s->system_key,
            ])->values()->all();
    }

    /** @return array<int, string> */
    public function activeChannels(Workspace $workspace): array
    {
        return DB::table('channel_accounts')->where('workspace_id', $workspace->id)->where('status', 'active')
            ->pluck('channel')->map(fn ($c) => (string) $c)->unique()->values()->all();
    }

    /**
     * Plantillas existentes del canal; las de WhatsApp, con el estado de Meta.
     *
     * @return array<int, array<string, mixed>>
     */
    public function templates(Workspace $workspace, string $channel): array
    {
        $rows = DB::table('templates as t')->leftJoin('wa_templates as w', 'w.id', '=', 't.wa_template_id')
            ->where('t.workspace_id', $workspace->id)->where('t.channel', $channel)->where('t.status', '!=', 'archived')
            ->orderByDesc('t.updated_at')->limit(60)
            ->get(['t.id', 't.name', 't.category', 't.subject', 't.library_key', 't.body_text', 'w.status as wa_status', 'w.name as wa_name', 'w.category as wa_category']);

        return $rows->map(fn ($t) => array_filter([
            'id' => CampaignPresenter::template((string) $t->id),
            'name' => (string) $t->name,
            'category' => (string) $t->category,
            'subject' => $t->subject,
            'library_key' => $t->library_key,
            'text' => $channel !== 'email' ? mb_substr((string) $t->body_text, 0, 300) : null,
            'approved' => $channel === 'whatsapp' ? $t->wa_status === 'APPROVED' : null,
            'meta_category' => $t->wa_category,
        ], fn ($v) => $v !== null && $v !== ''))->values()->all();
    }

    /**
     * Diseños de correo de marketing de la biblioteca (M08) con sus textos editables.
     *
     * @return list<array{key: string, name: string, description: string, slots: list<string>}>
     */
    public function emailLayouts(): array
    {
        $out = [];
        foreach (TemplateLibrary::catalog() as $entry) {
            if ($entry['channel'] !== 'email' || $entry['category'] !== 'marketing') {
                continue;
            }
            $out[] = ['key' => (string) $entry['key'], 'name' => (string) $entry['name'], 'description' => (string) $entry['description'], 'slots' => self::slots((string) $entry['key'])];
        }

        return $out;
    }

    /** @return list<string> */
    public static function slots(string $libraryKey): array
    {
        $file = app_path('Domain/Templates/Library/email/'.basename($libraryKey).'.mjml');
        $found = is_file($file) && preg_match_all('/\[\[([a-z_]+)\]\]/', (string) file_get_contents($file), $m) > 0 ? $m[1] : [];

        return array_values(array_intersect(self::COPY_SLOTS, array_unique(['subject', 'preheader', ...$found])));
    }

    /** @return list<array<string, mixed>> */
    public function calendar(Workspace $workspace, ?int $year = null): array
    {
        $tz = $workspace->timezone ?: 'America/Bogota';
        $today = CarbonImmutable::now($tz)->toDateString();
        $year ??= (int) CarbonImmutable::now($tz)->year;
        $country = in_array($workspace->country, ['CO', 'MX', 'VE'], true) ? (string) $workspace->country : 'CO';
        $events = [...CommercialCalendar::forYear($year, $country), ...CommercialCalendar::forYear($year + 1, $country)];

        return array_values(array_slice(array_filter($events, fn (array $e) => $e['ends'] >= $today), 0, 8));
    }
}
