<?php

declare(strict_types=1);

namespace App\Domain\Flows\Graph;

/**
 * Catálogo de nodos de modulos.md M09. Cada tipo dice su categoría y las ramas
 * de salida que acepta (`null` es la salida normal). `dynamic` = las ramas salen
 * de la configuración (variantes A/B, canales). Ya no quedan nodos bloqueados.
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

    public const GIVE_POINTS = 'give_points';

    public const GIVE_WALLET = 'give_wallet';

    public const ASSIGN_AGENT = 'assign_agent';

    public const LEAD_STAGE = 'lead_stage';

    public const CRM_TASK = 'crm_task';

    public const LEAD_FOLLOWUP = 'lead_followup';

    /** @var array<string, array{category: string, branches: list<string|null>|'dynamic'}> */
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
        // Fidelización (M13): dar puntos o saldo a favor.
        self::GIVE_POINTS => ['category' => 'action', 'branches' => [null]],
        self::GIVE_WALLET => ['category' => 'action', 'branches' => [null]],
        // Leads y bandeja (M18, M11): asignar a una persona, mover la etapa del lead, crear una tarea
        // para «Hoy» y el seguimiento con IA del lead (flujo 18).
        self::ASSIGN_AGENT => ['category' => 'action', 'branches' => [null, 'failed']],
        self::LEAD_STAGE => ['category' => 'action', 'branches' => [null, 'failed']],
        self::CRM_TASK => ['category' => 'action', 'branches' => [null]],
        self::LEAD_FOLLOWUP => ['category' => 'action', 'branches' => [null, 'failed']],
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
