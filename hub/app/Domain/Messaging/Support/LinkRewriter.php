<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Models\TrackedLink;

/**
 * Cambia cada URL http(s) del contenido por un enlace rastreado
 * `{links_url}/c/{code}` (hub/CLAUDE.md §8). No toca mailto:, tel:, anclas ni
 * los enlaces de baja y preferencias. La misma URL dentro de un mensaje usa un
 * solo código.
 */
final class LinkRewriter
{
    /** @var array<string, string> URL → código */
    private array $codes = [];

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $messageId,
        private readonly ?string $contactId,
    ) {}

    public function html(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        return (string) preg_replace_callback('/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(https?:\/\/[^"\']+)\2/i', function (array $m) {
            return $m[1].$m[2].htmlspecialchars($this->track(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5)), ENT_QUOTES).$m[2];
        }, $html);
    }

    public function text(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return (string) preg_replace_callback('#https?://[^\s<>"\')\]]+#i', fn (array $m) => $this->track($m[0]), $text);
    }

    /**
     * Parámetros de plantillas de WhatsApp: los de texto con URL se rastrean; el
     * de un botón URL solo si la plantilla apunta a `{links_url}/c/{{1}}` (el
     * parámetro es entonces el código).
     *
     * @param  list<array<string, mixed>>  $components
     * @return list<array<string, mixed>>
     */
    public function waComponents(array $components): array
    {
        foreach ($components as &$component) {
            $isUrlButton = ($component['type'] ?? '') === 'button' && ($component['sub_type'] ?? '') === 'url';
            foreach ((array) ($component['parameters'] ?? []) as $i => $param) {
                if (! is_array($param) || ($param['type'] ?? '') !== 'text' || ! is_string($param['text'] ?? null)) {
                    continue;
                }
                if ($isUrlButton && preg_match('#^https?://#i', $param['text']) === 1) {
                    $component['parameters'][$i]['text'] = $this->code($param['text']);
                } elseif (! $isUrlButton) {
                    $component['parameters'][$i]['text'] = (string) $this->text($param['text']);
                }
            }
        }

        return $components;
    }

    /** @return list<string> códigos creados */
    public function created(): array
    {
        return array_values($this->codes);
    }

    private function track(string $url): string
    {
        // La baja, las preferencias y los ya rastreados quedan tal cual (antes salían como `/c/https://…/u/…`).
        return self::excluded($url) ? $url : ContactLinkToken::base().'/c/'.$this->code($url);
    }

    private static function excluded(string $url): bool
    {
        $base = ContactLinkToken::base();

        return str_starts_with($url, $base.'/u/') || str_starts_with($url, $base.'/preferencias/') || str_starts_with($url, $base.'/c/');
    }

    private function code(string $url): string
    {
        if (self::excluded($url)) {
            return $url;
        }
        if (! isset($this->codes[$url])) {
            for ($i = 0; $i < 5; $i++) {
                $code = LinkCode::generate();
                if (TrackedLink::withoutWorkspaceScope()->whereKey($code)->doesntExist()) {
                    break;
                }
            }
            TrackedLink::query()->create([
                'code' => $code,
                'workspace_id' => $this->workspaceId,
                'message_id' => $this->messageId,
                'contact_id' => $this->contactId,
                'url' => mb_substr($url, 0, 4000),
            ]);
            $this->codes[$url] = $code;
        }

        return $this->codes[$url];
    }
}
