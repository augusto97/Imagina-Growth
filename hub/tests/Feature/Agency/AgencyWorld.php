<?php

declare(strict_types=1);

namespace Tests\Feature\Agency;

use App\Domain\Workspaces\Enums\AccountType;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dos agencias: «Imagina La Web» con dos clientes (A y B, cada uno con su dueño
 * directo) y otra agencia con su propio cliente C. Personas de la agencia sin
 * membresía directa en los clientes.
 */
final class AgencyWorld
{
    public Account $agency;

    public Account $foreign;

    public User $owner;      // agency_owner

    public User $member;     // agency_member

    public User $foreignOwner;

    public User $clientOwnerA;

    public User $clientOwnerB;

    public Workspace $a;

    public Workspace $b;

    public Workspace $c;

    public static function make(TestCase $t): self
    {
        $w = new self;
        $w->agency = Account::query()->create(['type' => AccountType::Agency, 'name' => 'Imagina La Web', 'country' => 'CO', 'status' => 'active']);
        $w->foreign = Account::query()->create(['type' => AccountType::Agency, 'name' => 'Otra Agencia', 'country' => 'MX', 'status' => 'active']);
        $w->owner = $t->userWith2fa(['name' => 'Ana Agencia']);
        $w->member = $t->userWith2fa(['name' => 'Beto Agencia']);
        $w->foreignOwner = $t->userWith2fa();
        $w->agency->users()->attach($w->owner->id, ['role' => 'agency_owner']);
        $w->agency->users()->attach($w->member->id, ['role' => 'agency_member']);
        $w->foreign->users()->attach($w->foreignOwner->id, ['role' => 'agency_owner']);

        $w->clientOwnerA = $t->userWith2fa(['name' => 'Carla Cliente']);
        $w->clientOwnerB = $t->userWith2fa();
        $w->a = self::moveTo($t->workspaceFor($w->clientOwnerA, 'Pinturas La 14'), $w->agency);
        $w->b = self::moveTo($t->workspaceFor($w->clientOwnerB, 'Café Sierra'), $w->agency);
        $w->c = self::moveTo($t->workspaceFor($t->userWith2fa(), 'Tienda Ajena'), $w->foreign);
        foreach (['owner', 'member', 'foreignOwner', 'clientOwnerA', 'clientOwnerB'] as $p) {
            $w->{$p} = $w->{$p}->refresh();
        }

        return $w;
    }

    public function api(Workspace $ws, string $path = ''): string
    {
        return '/api/w/'.$ws->publicId().'/'.ltrim($path, '/');
    }

    /** Inserta un pedido pagado (o no) en el espacio de trabajo. */
    public static function order(Workspace $ws, float $total, array $overrides = []): string
    {
        $id = strtoupper((string) Str::ulid());
        DB::table('orders')->insert($overrides + [
            'id' => $id, 'workspace_id' => $ws->id, 'site_id' => 'S1', 'external_id' => (string) random_int(1, 999_999_999),
            'status' => 'processing', 'is_paid' => true, 'paid_at' => now()->subHour(), 'currency' => 'COP',
            'total' => $total, 'total_ws' => $total, 'created_at' => now()->subHour(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private static function moveTo(Workspace $ws, Account $account): Workspace
    {
        $old = $ws->account_id;
        $ws->forceFill(['account_id' => $account->id])->save();
        DB::table('accounts')->where('id', $old)->delete();

        return $ws->refresh();
    }
}
