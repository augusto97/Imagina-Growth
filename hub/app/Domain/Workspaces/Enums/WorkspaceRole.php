<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Enums;

/**
 * Roles por espacio de trabajo (modulos.md M01).
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Marketing = 'marketing';
    case Agent = 'agent';
    case Viewer = 'viewer';

    /**
     * Permisos por rol. Las abilities se registran como Gates `ws.<ability>`.
     *
     * @var array<string, list<string>>
     */
    private const ABILITIES = [
        'viewWorkspace' => ['owner', 'admin', 'marketing', 'agent', 'viewer'],
        'viewDashboards' => ['owner', 'admin', 'marketing', 'viewer'],
        'manageWorkspace' => ['owner', 'admin'],
        'deleteWorkspace' => ['owner'],
        'manageBilling' => ['owner'],
        'manageMembers' => ['owner', 'admin'],
        'viewSites' => ['owner', 'admin', 'marketing', 'viewer'],
        'manageSites' => ['owner', 'admin'],
        'viewAllContacts' => ['owner', 'admin', 'marketing'],
        'viewAssignedContacts' => ['owner', 'admin', 'marketing', 'agent'],
        'exportContacts' => ['owner', 'admin'],
        'deleteContacts' => ['owner', 'admin'],
        'mergeContacts' => ['owner', 'admin'],
        'editContacts' => ['owner', 'admin', 'marketing'],
        'viewPaymentData' => ['owner', 'admin'],
        'manageMarketing' => ['owner', 'admin', 'marketing'],
        'viewAuditLog' => ['owner', 'admin'],
        // Fase 4: bandeja (M11) e IA (M17).
        'useInbox' => ['owner', 'admin', 'marketing', 'agent'],
        'manageInbox' => ['owner', 'admin'],
        'useAi' => ['owner', 'admin', 'marketing'],
        // Fase 5: leads y agenda (M18), API e integraciones (M19) y agencia (M22). `viewAgency` no
        // sale de ningún rol del espacio de trabajo: solo la tienen las personas de la cuenta de
        // agencia dueña (account_user), ver AgencyAccess::extraAbilities. Un dueño directo del
        // cliente no ve a los demás clientes de la agencia.
        'useLeads' => ['owner', 'admin', 'marketing', 'agent'],
        'manageServices' => ['owner', 'admin'],
        'manageApi' => ['owner', 'admin'],
        'viewAgency' => [],
    ];

    public function can(string $ability): bool
    {
        return in_array($this->value, self::ABILITIES[$ability] ?? [], true);
    }

    /** @return list<string> */
    public static function abilities(): array
    {
        return array_keys(self::ABILITIES);
    }

    /** @return list<string> */
    public function grantedAbilities(): array
    {
        return array_values(array_filter(self::abilities(), fn (string $a) => $this->can($a)));
    }

    public function requiresTwoFactor(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /** Rol efectivo de un miembro de la agencia en los espacios de trabajo de su cuenta. */
    public static function fromAccountRole(AccountRole $role): self
    {
        return match ($role) {
            AccountRole::AgencyOwner => self::Owner,
            AccountRole::AgencyMember => self::Admin,
        };
    }
}
