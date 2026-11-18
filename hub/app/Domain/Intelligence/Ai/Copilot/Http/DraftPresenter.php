<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot\Http;

use App\Domain\Intelligence\Ai\Copilot\Models\AiDraft;

final class DraftPresenter
{
    /** @return array<string, mixed> */
    public static function make(AiDraft $draft, bool $full = true): array
    {
        $proposal = $draft->proposal;

        return [
            'id' => $draft->publicId(),
            'kind' => $draft->kind,
            'status' => $draft->status,
            'prompt' => $draft->prompt,
            'name' => $proposal['name'] ?? ($proposal['type'] ?? null),
            'summary' => $proposal['summary'] ?? null,
            'applied' => $proposal['applied'] ?? [],
            'created_at' => $draft->created_at?->toIso8601String(),
        ] + ($full ? ['proposal' => $proposal, 'model' => $draft->model] : []);
    }
}
