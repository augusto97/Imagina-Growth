<?php

declare(strict_types=1);

namespace App\Domain\Templates\Http;

use App\Domain\Templates\Models\Template;
use Illuminate\Support\Facades\DB;

/** JSON de una plantilla para la SPA. */
final class TemplatePresenter
{
    /**
     * `$wa` es la plantilla de Meta enlazada (nombre, estado de la revisión y motivo del rechazo) para decir en
     * palabras en qué va la aprobación; sin ella se busca (en la lista llega ya leída en una sola consulta).
     *
     * @param  array{name: string, status: string, rejected_reason: string|null}|null  $wa
     * @return array<string, mixed>
     */
    public static function summary(Template $t, ?array $wa = null, bool $lookup = true): array
    {
        if ($wa === null && $lookup && $t->wa_template_id !== null) {
            $row = DB::table('wa_templates')->where('workspace_id', $t->workspace_id)->where('id', $t->wa_template_id)->first(['name', 'status', 'rejected_reason']);
            $wa = $row === null ? null : ['name' => (string) $row->name, 'status' => (string) $row->status, 'rejected_reason' => $row->rejected_reason !== null ? (string) $row->rejected_reason : null];
        }

        return [
            'id' => $t->publicId(),
            'channel' => $t->channel,
            'name' => $t->name,
            'category' => $t->category,
            'subject' => $t->subject,
            // Lo que se ve bajo el nombre en la lista: el asunto o el inicio del SMS.
            'excerpt' => $t->channel === 'sms' ? mb_substr(trim((string) $t->body_text), 0, 120) : $t->subject,
            'status' => $t->status,
            'library_key' => $t->library_key,
            'wa_template_name' => $wa['name'] ?? null,
            // APPROVED, PENDING, REJECTED, PAUSED o DISABLED (Meta); null si no está enlazada.
            'wa_status' => $wa !== null ? strtoupper($wa['status']) : null,
            'wa_rejected_reason' => $wa['rejected_reason'] ?? null,
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array{level: string, code: string, message: string}>  $checks
     * @return array<string, mixed>
     */
    public static function full(Template $t, array $checks): array
    {
        return self::summary($t) + [
            'preheader' => $t->preheader,
            'body_mjml' => $t->body_mjml,
            'body_html' => $t->body_html,
            'body_text' => $t->body_text,
            'editor_state' => $t->editor_state,
            'wa_template_id' => $t->wa_template_id,
            'variables' => $t->variables ?? (object) [],
            'checks' => $checks,
        ];
    }
}
