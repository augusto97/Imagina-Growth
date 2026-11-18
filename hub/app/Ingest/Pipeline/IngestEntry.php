<?php

declare(strict_types=1);

namespace App\Ingest\Pipeline;

/** Una entrada del stream ingest:{shard}. */
final class IngestEntry
{
    /** @param array<string, mixed>|null $envelope */
    public function __construct(
        public readonly string $streamId,
        public readonly string $workspaceId,
        public readonly int $workspaceChId,
        public readonly string $siteId,
        public readonly int $siteChId,
        public readonly int $receivedMs,
        public readonly string $ip,
        public readonly string $ua,
        public readonly string $json,
        public ?array $envelope = null,
    ) {}

    /** @param array<string, string> $fields */
    public static function fromStream(string $id, array $fields): self
    {
        return new self(
            $id,
            (string) ($fields['ws'] ?? ''),
            (int) ($fields['wsc'] ?? 0),
            (string) ($fields['site'] ?? ''),
            (int) ($fields['sitec'] ?? 0),
            (int) ($fields['rcv'] ?? 0),
            (string) ($fields['ip'] ?? ''),
            (string) ($fields['ua'] ?? ''),
            (string) ($fields['e'] ?? ''),
        );
    }
}
