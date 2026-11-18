<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Data;

/**
 * Todo lo que trae un webhook. `account` ayuda cuando el proveedor manda un solo
 * webhook para varias cuentas (Meta: phone_number_id en cada cambio).
 */
final class WebhookBatch
{
    /**
     * @param  list<array{account: string|null, update: StatusUpdate}>  $statuses
     * @param  list<array{account: string|null, message: InboundMessage}>  $inbound
     * @param  list<array{account: string|null, meta_template_id: string|null, name: string|null, language: string|null, status: string, reason: string|null}>  $templates
     * @param  list<array{account: string|null, quality: string|null, tier: string|null, display_phone_number?: string|null}>  $quality
     */
    public function __construct(
        public array $statuses = [],
        public array $inbound = [],
        public array $templates = [],
        public array $quality = [],
        public ?string $challenge = null,
    ) {}
}
