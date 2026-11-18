<?php

use App\Domain\Workspaces\Enums\AccountRole;
use App\Domain\Workspaces\Enums\WorkspaceRole;

it('aplica la matriz de roles de M01', function () {
    expect(WorkspaceRole::Owner->can('manageBilling'))->toBeTrue()
        ->and(WorkspaceRole::Admin->can('manageBilling'))->toBeFalse()
        ->and(WorkspaceRole::Admin->can('manageSites'))->toBeTrue()
        ->and(WorkspaceRole::Marketing->can('manageMarketing'))->toBeTrue()
        ->and(WorkspaceRole::Marketing->can('viewPaymentData'))->toBeFalse()
        ->and(WorkspaceRole::Marketing->can('exportContacts'))->toBeFalse()
        ->and(WorkspaceRole::Agent->can('viewAllContacts'))->toBeFalse()
        ->and(WorkspaceRole::Agent->can('viewAssignedContacts'))->toBeTrue()
        ->and(WorkspaceRole::Viewer->can('viewDashboards'))->toBeTrue()
        ->and(WorkspaceRole::Viewer->can('manageMarketing'))->toBeFalse();
});

it('exige 2FA solo a owner y admin y mapea los roles de agencia', function () {
    expect(WorkspaceRole::Owner->requiresTwoFactor())->toBeTrue()
        ->and(WorkspaceRole::Admin->requiresTwoFactor())->toBeTrue()
        ->and(WorkspaceRole::Marketing->requiresTwoFactor())->toBeFalse()
        ->and(WorkspaceRole::fromAccountRole(AccountRole::AgencyOwner))->toBe(WorkspaceRole::Owner)
        ->and(WorkspaceRole::fromAccountRole(AccountRole::AgencyMember))->toBe(WorkspaceRole::Admin);
});
