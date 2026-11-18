<?php

declare(strict_types=1);

namespace App\Domain\Consent\Actions;

use App\Domain\Consent\Models\LegalText;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Textos de consentimiento vigentes por canal (cumplimiento.md §3.1, §11.5).
 * Si el espacio de trabajo no tiene, crea la versión v1 con el texto por
 * defecto, prudente y editable [validar con abogado].
 */
final class ConsentTexts
{
    public const CHANNELS = ['whatsapp', 'email'];

    /**
     * @return list<array{kind: string, channel: string, version: string, label: string}>
     */
    public function current(Workspace $workspace): array
    {
        $out = [];
        foreach (self::CHANNELS as $channel) {
            $text = $this->latest($workspace, $channel) ?? $this->createDefault($workspace, $channel);
            $out[] = ['kind' => 'marketing_consent', 'channel' => $channel, 'version' => $text->version, 'label' => $text->body];
        }

        return $out;
    }

    public function latest(Workspace $workspace, string $channel): ?LegalText
    {
        return LegalText::query()
            ->where('workspace_id', $workspace->id)
            ->where('kind', 'marketing_consent')
            ->where('channel', $channel)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->orderByRaw('CAST(SUBSTRING(version, 2) AS UNSIGNED) DESC')
            ->first();
    }

    /** Publica una versión nueva (las anteriores quedan como historial). */
    public function publish(Workspace $workspace, string $channel, string $body): LegalText
    {
        $count = LegalText::query()->where('workspace_id', $workspace->id)->where('kind', 'marketing_consent')->where('channel', $channel)->count();

        return LegalText::query()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'marketing_consent',
            'channel' => $channel,
            'version' => 'v'.($count + 1),
            'body' => trim($body),
            'published_at' => now(),
        ]);
    }

    private function createDefault(Workspace $workspace, string $channel): LegalText
    {
        $name = (string) ($workspace->getAttribute('legal_name') ?: $workspace->name);
        $body = match ($channel) {
            'whatsapp' => __('Quiero recibir ofertas y novedades de :name por WhatsApp. Puedo darme de baja cuando quiera respondiendo «baja».', ['name' => $name]),
            default => __('Quiero recibir ofertas y novedades de :name por correo. Puedo darme de baja cuando quiera desde cualquier correo.', ['name' => $name]),
        };

        return LegalText::query()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'marketing_consent',
            'channel' => $channel,
            'version' => 'v1',
            'body' => $body,
            'published_at' => now(),
        ]);
    }
}
