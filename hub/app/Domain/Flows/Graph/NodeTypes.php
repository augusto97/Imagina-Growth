<?php

declare(strict_types=1);

namespace App\Domain\Flows\Graph;

/**
 * Catálogo de nodos de modulos.md M09. Cada tipo dice su categoría, las ramas
 * de salida que acepta (`null` es la salida normal) y, si llega en otra fase,
 * cuál. `dynamic` = las ramas salen de la configuración (variantes A/B, canales).
 */
final class NodeTypes
{
    public const TRIGGER = 'trigger';

    public const WAIT = 'wait';

    public const WAIT_EVENT = 'wait_event';

    public const CONDITION = 'condition';

    public const AB_SPLIT = 'ab_split';

    public const CHANNEL_SPLIT = 'channel_split';

    public const SEND_WHATSAPP = 'send_whatsapp';

    public const SEND_EMAIL = 'send_email';

    public const SEND_SMS = 'send_sms';

    public const COUPON = 'coupon';

    public const TAG = 'tag';

    public const UPDATE_ATTRIBUTE = 'update_attribute';

    public const NOTIFY_TEAM = 'notify_team';

    public const WEBHOOK = 'webhook';

    public const EXIT = 'exit';

    /** @var array<string, array{category: string, branches: list<string|null>|'dynamic', phase?: int}> */
    public const TYPES = [
        self::TRIGGER => ['category' => 'trigger', 'branches' => [null]],
        self::WAIT => ['category' => 'wait', 'branches' => [null]],
        self::WAIT_EVENT => ['category' => 'wait', 'branches' => ['event', 'timeout']],
        self::CONDITION => ['category' => 'logic', 'branches' => ['yes', 'no']],
        self::AB_SPLIT => ['category' => 'logic', 'branches' => 'dynamic'],
        self::CHANNEL_SPLIT => ['category' => 'logic', 'branches' => 'dynamic'],
        self::SEND_WHATSAPP => ['category' => 'action', 'branches' => [null, 'failed']],
        self::SEND_EMAIL => ['category' => 'action', 'branches' => [null, 'failed']],
        self::SEND_SMS => ['category' => 'action', 'branches' => [null, 'failed']],
        self::COUPON => ['category' => 'action', 'branches' => [null, 'failed']],
        self::TAG => ['category' => 'action', 'branches' => [null]],
        self::UPDATE_ATTRIBUTE => ['category' => 'action', 'branches' => [null]],
        self::NOTIFY_TEAM => ['category' => 'action', 'branches' => [null, 'failed']],
        self::WEBHOOK => ['category' => 'action', 'branches' => [null, 'failed']],
        self::EXIT => ['category' => 'control', 'branches' => []],
        // Llegan con sus módulos (CLAUDE.md §5).
        'give_points' => ['category' => 'action', 'branches' => [null], 'phase' => 3],
        'give_wallet' => ['category' => 'action', 'branches' => [null], 'phase' => 3],
        'assign_agent' => ['category' => 'action', 'branches' => [null], 'phase' => 4],
        'lead_stage' => ['category' => 'action', 'branches' => [null], 'phase' => 5],
        'crm_task' => ['category' => 'action', 'branches' => [null], 'phase' => 5],
    ];

    /** Nodos de espera: un ciclo solo es válido si pasa por uno. */
    public const WAITS = [self::WAIT, self::WAIT_EVENT];

    /** Nodos de envío y su canal. */
    public const SENDS = [self::SEND_WHATSAPP => 'whatsapp', self::SEND_EMAIL => 'email', self::SEND_SMS => 'sms'];

    public const CHANNELS = ['whatsapp', 'email', 'sms'];

    public const AB_KEYS = ['a', 'b', 'c', 'd'];

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function phase(string $type): ?int
    {
        return self::TYPES[$type]['phase'] ?? null;
    }

    /**
     * Ramas válidas para un nodo (con su configuración, para las dinámicas).
     *
     * @param  array<string, mixed>  $config
     * @return list<string|null>
     */
    public static function branches(string $type, array $config): array
    {
        $branches = self::TYPES[$type]['branches'] ?? [];
        if ($branches !== 'dynamic') {
            return $branches;
        }
        if ($type === self::AB_SPLIT) {
            return array_values(array_map(fn ($v) => (string) ($v['key'] ?? ''), (array) ($config['variants'] ?? [])));
        }

        // channel_split: un canal por rama, más «none» si ninguno está disponible.
        return [...array_values(array_map('strval', (array) ($config['channels'] ?? []))), 'none'];
    }

    public static function channelOf(string $type): ?string
    {
        return self::SENDS[$type] ?? null;
    }
}
