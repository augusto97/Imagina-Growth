<?php

declare(strict_types=1);

namespace App\Ingest\Http;

use Illuminate\Http\JsonResponse;

final class IngestResult
{
    /** @var list<array{event_id: string|null, reason: string}> */
    public array $rejected = [];

    public int $accepted = 0;

    public function reject(?string $eventId, string $reason): void
    {
        $this->rejected[] = ['event_id' => $eventId, 'reason' => $reason];
    }

    public function toResponse(): JsonResponse
    {
        return response()->json(['accepted' => $this->accepted, 'rejected' => $this->rejected], 202);
    }
}
