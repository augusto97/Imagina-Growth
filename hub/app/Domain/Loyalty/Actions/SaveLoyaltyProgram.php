<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Actions;

use App\Domain\Loyalty\Jobs\RecalculateTiersJob;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyReward;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Loyalty\Support\ProgramRules;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * El programa de fidelización del espacio de trabajo (modulos.md M13): crearlo con los valores por defecto, guardar
 * sus reglas y su estado, sus niveles y sus recompensas. Lo usan Fidelización (que valida lo que llega del panel) y la
 * estrategia «Puntos y niveles». Cada cambio queda en la auditoría; al quedar activo se recalculan los niveles.
 */
final class SaveLoyaltyProgram
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly DatabaseLoyaltyLedger $ledger,
        private readonly RecordAuditLog $audit,
    ) {}

    /** El programa, o uno nuevo en borrador con las reglas por defecto (ProgramRules::defaults). */
    public function ensure(): LoyaltyProgram
    {
        $program = LoyaltyProgram::query()->first();
        if ($program === null) {
            $d = ProgramRules::defaults();
            $program = LoyaltyProgram::query()->create([
                'status' => 'draft', 'currency_name' => $d['currency_name'], 'earn_rules' => $d['earn_rules'],
                'redeem_rules' => $d['redeem_rules'], 'expiry_policy' => $d['expiry_policy'], 'pending_days' => $d['pending_days'], 'wallet_rules' => $d['wallet_rules'],
            ]);
            $this->ledger->forgetProgram();
        }

        return $program;
    }

    /**
     * Guarda las reglas ya validadas (y el estado si viene). Crea el programa la primera vez.
     *
     * @param  array<string, mixed>  $rules  currency_name, earn_rules, redeem_rules, expiry_policy, pending_days, wallet_rules
     */
    public function handle(array $rules, ?string $status = null): LoyaltyProgram
    {
        $program = LoyaltyProgram::query()->first();
        $wasActive = $program?->isActive() ?? false;
        if ($program === null) {
            $program = LoyaltyProgram::query()->create($rules + ['status' => $status ?? 'draft']);
        } else {
            $program->fill($rules + ($status !== null ? ['status' => $status] : []))->save();
        }

        return $this->saved($program, $wasActive);
    }

    /** Cambia solo el estado (activar o pausar desde una estrategia). */
    public function setStatus(LoyaltyProgram $program, string $status): LoyaltyProgram
    {
        $wasActive = $program->isActive();
        $program->forceFill(['status' => $status])->save();

        return $this->saved($program, $wasActive);
    }

    /**
     * La lista completa de niveles, de menor a mayor (el orden es el rango), ya validada.
     *
     * @param  list<array{id?: string|null, name: string, threshold_type: string, threshold: float|int|string, benefits?: array<string, mixed>, wc_role?: string|null}>  $tiers
     * @param  callable(string|null): (string|null)  $decodeId  ID público → ID interno
     */
    public function saveTiers(LoyaltyProgram $program, array $tiers, callable $decodeId): void
    {
        DB::transaction(function () use ($tiers, $program, $decodeId): void {
            $keep = [];
            // Rangos temporales para no chocar con el índice único (program_id, rank) al reordenar.
            LoyaltyTier::query()->where('program_id', $program->id)->update(['rank' => DB::raw('`rank` + 1000')]);
            $existing = LoyaltyTier::query()->where('program_id', $program->id)->get()->keyBy('id');
            foreach (array_values($tiers) as $i => $t) {
                $id = $decodeId($t['id'] ?? null);
                $tier = $id !== null ? $existing->get($id) : null;
                $attrs = [
                    'name' => trim($t['name']),
                    'rank' => $i + 1,
                    'threshold_type' => $t['threshold_type'],
                    'threshold' => number_format((float) $t['threshold'], 2, '.', ''),
                    'benefits' => [
                        'multiplier' => (float) ($t['benefits']['multiplier'] ?? 1),
                        'free_shipping' => (bool) ($t['benefits']['free_shipping'] ?? false),
                        'early_access' => (bool) ($t['benefits']['early_access'] ?? false),
                        'gifts' => trim((string) ($t['benefits']['gifts'] ?? '')),
                    ],
                    'wc_role' => isset($t['wc_role']) && $t['wc_role'] !== '' ? $t['wc_role'] : null,
                ];
                if ($tier === null) {
                    $tier = LoyaltyTier::query()->create($attrs + ['program_id' => $program->id]);
                } else {
                    $tier->fill($attrs)->save();
                }
                $keep[] = $tier->id;
            }
            LoyaltyTier::query()->where('program_id', $program->id)->whereNotIn('id', $keep)->delete();
        });
        $this->audit->handle('loyalty.tiers_saved', $program, ['tiers' => array_map(fn ($t) => $t['name'].' ≥ '.$t['threshold'], $tiers)], $this->current->id());
        if ($program->isActive()) {
            RecalculateTiersJob::dispatch($this->current->id());
        }
    }

    /** @param array{name: string, kind: string, points_cost: int, value: string, config: array<string, mixed>|null} $data */
    public function createReward(LoyaltyProgram $program, array $data): LoyaltyReward
    {
        $reward = LoyaltyReward::query()->create($data + ['program_id' => $program->id, 'status' => 'active']);
        $this->audit->handle('loyalty.reward_created', $reward, ['name' => $reward->name, 'kind' => $reward->kind, 'points_cost' => $reward->points_cost], $this->current->id());

        return $reward;
    }

    private function saved(LoyaltyProgram $program, bool $wasActive): LoyaltyProgram
    {
        $this->ledger->forgetProgram();
        $this->audit->handle('loyalty.program_saved', $program, ['status' => $program->status, 'currency_name' => $program->currency_name, 'pending_days' => $program->pending_days], $this->current->id());
        if (! $wasActive && $program->isActive()) {
            RecalculateTiersJob::dispatch($this->current->id());
        }

        return $program;
    }
}
