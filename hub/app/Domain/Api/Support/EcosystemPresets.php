<?php

declare(strict_types=1);

namespace App\Domain\Api\Support;

/**
 * Integraciones con los productos de Imagina La Web (M19): cada una es un token con los
 * alcances que necesita y, si aplica, un webhook con sus eventos. El panel las conecta en un
 * paso. [verificar] Las URL y los nombres exactos de cada producto los confirma su equipo.
 */
final class EcosystemPresets
{
    /** @var array<string, array{name: string, scopes: list<string>, events: list<string>, include_pii: bool}> */
    public const ALL = [
        'crm' => [
            'name' => 'Imagina CRM',
            'scopes' => ['contacts:read', 'contacts:write', 'contacts:pii', 'leads:read', 'leads:write', 'orders:read'],
            'events' => ['contact.created', 'form.submitted', 'lead.*', 'appointment.*', 'quote.requested', 'order.paid'],
            'include_pii' => true,
        ],
        'base' => [
            'name' => 'Imagina Base',
            'scopes' => ['contacts:read', 'orders:read', 'segments:read'],
            'events' => ['order.*', 'customer.registered'],
            'include_pii' => false,
        ],
        'proposals' => [
            'name' => 'Imagina Proposals',
            'scopes' => ['contacts:read', 'leads:read', 'leads:write'],
            'events' => ['lead.stage_changed', 'lead.won', 'quote.requested'],
            'include_pii' => false,
        ],
        'reports' => [
            'name' => 'Imagina Reports',
            'scopes' => ['analytics:read', 'segments:read', 'orders:read'],
            'events' => [],
            'include_pii' => false,
        ],
        'search' => [
            'name' => 'Imagina Search',
            'scopes' => ['analytics:read', 'events:write'],
            'events' => [],
            'include_pii' => false,
        ],
        'turbo' => [
            'name' => 'Imagina Turbo',
            'scopes' => ['events:write'],
            'events' => [],
            'include_pii' => false,
        ],
    ];
}
