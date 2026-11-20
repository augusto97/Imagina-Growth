<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Coexistence;

use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * La libreta de contactos de la app de WhatsApp Business (`smb_app_state_sync`, coexistencia): crea o completa cada
 * contacto con su nombre y teléfono, **sin consentimiento** y con su origen (BusinessAppContacts). Una baja en la
 * libreta del celular no borra a la persona de la plataforma (puede tener pedidos o una conversación): se ignora.
 */
final class SyncBusinessAppContacts
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly BusinessAppContacts $contacts) {}

    /**
     * @param  list<array{phone: string|null, user_id: string|null, full_name: string|null, first_name: string|null, action: string}>  $contacts
     * @return array{created: int, updated: int, skipped: int}
     */
    public function handle(ChannelAccount $account, array $contacts): array
    {
        $workspace = Workspace::query()->find($account->workspace_id);
        if ($workspace === null) {
            return ['created' => 0, 'updated' => 0, 'skipped' => count($contacts)];
        }

        return $this->current->run($workspace, function () use ($workspace, $account, $contacts): array {
            $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0];
            foreach ($contacts as $c) {
                $resolved = $c['action'] === 'add' ? $this->contacts->resolve($workspace, $c['phone'], $c['user_id'], $c['full_name'], $c['first_name']) : null;
                $totals[$resolved === null ? 'skipped' : ($resolved[1] ? 'created' : 'updated')]++;
            }
            BusinessAppMessages::state($account, 'contacts', fn (array $s): array => [
                'created' => (int) ($s['created'] ?? 0) + $totals['created'],
                'updated' => (int) ($s['updated'] ?? 0) + $totals['updated'],
                'updated_at' => now()->toIso8601String(),
            ]);

            return $totals;
        });
    }
}
