<?php

declare(strict_types=1);

namespace App\Domain\Templates\Http;

use App\Domain\Templates\Models\Template;

/** JSON de una plantilla para la SPA. */
final class TemplatePresenter
{
    /** @return array<string, mixed> */
    public static function summary(Template $t, ?string $waName = null): array
    {
        return [
            'id' => $t->publicId(),
            'channel' => $t->channel,
            'name' => $t->name,
            'category' => $t->category,
            'subject' => $t->subject,
            'status' => $t->status,
            'library_key' => $t->library_key,
            'wa_template_name' => $waName,
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
