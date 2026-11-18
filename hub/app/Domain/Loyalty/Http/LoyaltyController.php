<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Http;

use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Jobs\RecalculateTiersJob;
use App\Domain\Loyalty\Ledger\Balances;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LedgerEntry;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyReward;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Loyalty\Queries\MemberStatus;
use App\Domain\Loyalty\Queries\ProgramStats;
use App\Domain\Loyalty\Support\ProgramExplainer;
use App\Domain\Loyalty\Support\ProgramRules;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Ids\PrefixedId;
use App\Support\Money\Decimal;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * API de Fidelización de la SPA (modulos.md M13). Ver: viewDashboards (los
 * miembros y el libro de un contacto: viewAllContacts); configurar y ajustar
 * saldos: manageMarketing, con auditoría.
 */
final class LoyaltyController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly DatabaseLoyaltyLedger $ledger) {}

    /** GET loyalty: programa, niveles, recompensas, categorías y la página explicativa. */
    public function show(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $workspace = $this->current->get();
        $program = LoyaltyProgram::query()->first();
        $tiers = $program !== null ? LoyaltyTier::query()->where('program_id', $program->id)->orderBy('rank')->get() : collect();
        $rewards = $program !== null ? LoyaltyReward::query()->where('program_id', $program->id)->orderBy('points_cost')->get() : collect();
        $members = DB::table('contacts')->where('workspace_id', $workspace->id)->whereNull('merged_into_id')->whereNotNull('tier_id')
            ->groupBy('tier_id')->selectRaw('tier_id, COUNT(*) AS n')->pluck('n', 'tier_id');
        $redeemed = DB::table('loyalty_ledger')->where('workspace_id', $workspace->id)->where('reason', 'redeem')->where('ref_type', 'reward')
            ->groupBy('ref_id')->selectRaw('ref_id, COUNT(*) AS n')->pluck('n', 'ref_id');
        $categories = DB::table('product_categories')->where('workspace_id', $workspace->id)->whereNull('deleted_at')
            ->orderBy('name')->limit(500)->get(['external_id', 'name'])
            ->map(fn ($c) => ['id' => (string) $c->external_id, 'name' => (string) $c->name])->unique('id')->values();

        return response()->json([
            'program' => LoyaltyPresenter::program($program),
            'tiers' => $tiers->map(fn (LoyaltyTier $t) => LoyaltyPresenter::tier($t, (int) ($members[$t->id] ?? 0)))->values(),
            'rewards' => $rewards->map(fn (LoyaltyReward $r) => LoyaltyPresenter::reward($r, (int) ($redeemed[$r->id] ?? 0)))->values(),
            'categories' => $categories,
            'currency' => $workspace->currency,
            'explainer' => $program !== null ? ProgramExplainer::build($program, $tiers, $rewards, $workspace) : null,
        ]);
    }

    /** PUT loyalty/program: reglas y estado (crea el programa la primera vez). */
    public function saveProgram(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(LoyaltyProgram::STATUSES)],
            'currency_name' => ['required', 'string', 'min:2', 'max:60'],
            'earn_rules' => ['required', 'array'],
            'earn_rules.per_amount.points' => ['required', 'integer', 'min:0', 'max:1000'],
            'earn_rules.per_amount.amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'earn_rules.basis' => ['sometimes', Rule::in(['without_shipping', 'total'])],
            'earn_rules.category_multipliers' => ['sometimes', 'array', 'max:30'],
            'earn_rules.category_multipliers.*.category_id' => ['required', 'string', 'max:64'],
            'earn_rules.category_multipliers.*.multiplier' => ['required', 'numeric', 'min:1', 'max:10'],
            'earn_rules.signup' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'earn_rules.birthday' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'earn_rules.review' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'earn_rules.review_media' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'earn_rules.referral' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'earn_rules.profile' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'earn_rules.campaigns' => ['sometimes', 'array', 'max:20'],
            'earn_rules.campaigns.*.name' => ['nullable', 'string', 'max:80'],
            'earn_rules.campaigns.*.multiplier' => ['required', 'numeric', 'min:1', 'max:10'],
            'earn_rules.campaigns.*.starts_on' => ['required', 'date_format:Y-m-d'],
            'earn_rules.campaigns.*.ends_on' => ['required', 'date_format:Y-m-d'],
            'redeem_rules.min_points' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'redeem_rules.coupon_expiry_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'expiry_policy.months' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'expiry_policy.warning_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'pending_days' => ['required', 'integer', 'min:0', 'max:120'],
            'wallet_rules.cashback_pct' => ['sometimes', 'numeric', 'min:0', 'max:50'],
        ]);
        foreach ((array) ($data['earn_rules']['campaigns'] ?? []) as $i => $c) {
            if ($c['ends_on'] < $c['starts_on']) {
                throw ValidationException::withMessages(["earn_rules.campaigns.{$i}.ends_on" => __('La campaña debe terminar después de empezar.')]);
            }
        }
        $earn = $data['earn_rules'];
        $clean = [
            'currency_name' => trim($data['currency_name']),
            'earn_rules' => [
                'per_amount' => ['points' => (int) $earn['per_amount']['points'], 'amount' => number_format((float) $earn['per_amount']['amount'], 2, '.', '')],
                'basis' => $earn['basis'] ?? 'without_shipping',
                'category_multipliers' => array_values(array_map(fn ($m) => ['category_id' => (string) $m['category_id'], 'multiplier' => (float) $m['multiplier']], (array) ($earn['category_multipliers'] ?? []))),
                'campaigns' => array_values(array_map(fn ($c) => ['name' => (string) ($c['name'] ?? ''), 'multiplier' => (float) $c['multiplier'], 'starts_on' => $c['starts_on'], 'ends_on' => $c['ends_on']], (array) ($earn['campaigns'] ?? []))),
            ] + array_map('intval', array_intersect_key($earn, array_flip(ProgramRules::FIXED_REASONS))),
            'redeem_rules' => ['min_points' => (int) ($data['redeem_rules']['min_points'] ?? 0), 'coupon_expiry_days' => (int) ($data['redeem_rules']['coupon_expiry_days'] ?? 30)],
            'expiry_policy' => ['months' => (int) ($data['expiry_policy']['months'] ?? 12), 'warning_days' => (int) ($data['expiry_policy']['warning_days'] ?? 15)],
            'pending_days' => (int) $data['pending_days'],
            'wallet_rules' => ['cashback_pct' => number_format((float) ($data['wallet_rules']['cashback_pct'] ?? 0), 2, '.', '')],
        ];
        $program = LoyaltyProgram::query()->first();
        $wasActive = $program?->isActive() ?? false;
        if ($program === null) {
            $program = LoyaltyProgram::query()->create($clean + ['status' => $data['status'] ?? 'draft']);
        } else {
            $program->fill($clean + (isset($data['status']) ? ['status' => $data['status']] : []))->save();
        }
        $this->ledger->forgetProgram();
        $audit->handle('loyalty.program_saved', $program, ['status' => $program->status, 'currency_name' => $program->currency_name, 'pending_days' => $program->pending_days], $this->current->id());
        if (! $wasActive && $program->isActive()) {
            RecalculateTiersJob::dispatch($this->current->id());
        }

        return $this->show();
    }

    /** PUT loyalty/tiers: la lista completa, de menor a mayor (el orden es el rango). */
    public function saveTiers(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'tiers' => ['present', 'array', 'max:8'],
            'tiers.*.id' => ['nullable', 'string', 'max:40'],
            'tiers.*.name' => ['required', 'string', 'max:80', 'distinct'],
            'tiers.*.threshold_type' => ['required', Rule::in(LoyaltyTier::THRESHOLD_TYPES)],
            'tiers.*.threshold' => ['required', 'numeric', 'min:0', 'max:10000000000'],
            'tiers.*.benefits.multiplier' => ['sometimes', 'numeric', 'min:1', 'max:10'],
            'tiers.*.benefits.free_shipping' => ['sometimes', 'boolean'],
            'tiers.*.benefits.early_access' => ['sometimes', 'boolean'],
            'tiers.*.benefits.gifts' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tiers.*.wc_role' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/'],
        ]);
        // validate() arma el arreglo regla por regla: el orden de los índices puede cambiar.
        $list = $data['tiers'];
        ksort($list);
        $data['tiers'] = array_values($list);
        $program = $this->programOrCreate();
        $previous = -1.0;
        foreach ($data['tiers'] as $i => $t) {
            if ($i > 0 && $t['threshold_type'] !== $data['tiers'][0]['threshold_type']) {
                throw ValidationException::withMessages(["tiers.{$i}.threshold_type" => __('Todos los niveles se miden igual (gasto, puntos o pedidos).')]);
            }
            if ((float) $t['threshold'] <= $previous) {
                throw ValidationException::withMessages(["tiers.{$i}.threshold" => __('Cada nivel debe pedir más que el anterior.')]);
            }
            $previous = (float) $t['threshold'];
        }
        DB::transaction(function () use ($data, $program): void {
            $keep = [];
            // Rangos temporales para no chocar con el índice único (program_id, rank) al reordenar.
            LoyaltyTier::query()->where('program_id', $program->id)->update(['rank' => DB::raw('`rank` + 1000')]);
            $existing = LoyaltyTier::query()->where('program_id', $program->id)->get()->keyBy('id');
            foreach (array_values($data['tiers']) as $i => $t) {
                $id = PrefixedId::decode(LoyaltyTier::ID_PREFIX, $t['id'] ?? null);
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
        $audit->handle('loyalty.tiers_saved', $program, ['tiers' => array_map(fn ($t) => $t['name'].' ≥ '.$t['threshold'], $data['tiers'])], $this->current->id());
        if ($program->isActive()) {
            RecalculateTiersJob::dispatch($this->current->id());
        }

        return $this->show();
    }

    /** POST loyalty/rewards */
    public function storeReward(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $program = $this->programOrCreate();
        $reward = LoyaltyReward::query()->create($this->rewardData($request) + ['program_id' => $program->id, 'status' => 'active']);
        $audit->handle('loyalty.reward_created', $reward, ['name' => $reward->name, 'kind' => $reward->kind, 'points_cost' => $reward->points_cost], $this->current->id());

        return response()->json(LoyaltyPresenter::reward($reward, 0), 201);
    }

    /** PATCH loyalty/rewards/{reward} */
    public function updateReward(Request $request, string $reward, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->findReward($reward);
        $status = $request->validate(['status' => ['sometimes', Rule::in(LoyaltyReward::STATUSES)]]);
        $data = $request->has('name') ? $this->rewardData($request) : [];
        $model->fill($data + $status)->save();
        $audit->handle('loyalty.reward_updated', $model, ['name' => $model->name, 'status' => $model->status, 'points_cost' => $model->points_cost], $this->current->id());

        return response()->json(LoyaltyPresenter::reward($model));
    }

    /** DELETE loyalty/rewards/{reward}: se archiva (los canjes hechos la siguen nombrando). */
    public function archiveReward(string $reward, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->findReward($reward);
        $model->forceFill(['status' => 'archived'])->save();
        $audit->handle('loyalty.reward_archived', $model, ['name' => $model->name], $this->current->id());

        return response()->json(['ok' => true]);
    }

    /** GET loyalty/stats?months= */
    public function stats(Request $request, ProgramStats $stats): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $months = max(3, min(12, (int) $request->query('months', 6)));

        return response()->json($stats->handle($this->current->get(), LoyaltyProgram::query()->first(), $months));
    }

    /** GET loyalty/members?q=&tier=&sort=&page= */
    public function members(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewAllContacts');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'tier' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', Rule::in(['points', 'wallet', 'recent'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Contact::query()->whereNull('merged_into_id')
            ->whereExists(fn ($q) => $q->from('loyalty_ledger as l')->whereColumn('l.contact_id', 'contacts.id'));
        if (($filters['q'] ?? '') !== '') {
            $this->search($query, trim((string) $filters['q']));
        }
        if (($filters['tier'] ?? null) !== null) {
            $query->where('tier_id', PrefixedId::decode(LoyaltyTier::ID_PREFIX, $filters['tier']) ?? '-');
        }
        match ($filters['sort'] ?? 'points') {
            'wallet' => $query->orderByDesc('wallet_balance_ws'),
            'recent' => $query->orderByDesc('last_seen_at'),
            default => $query->orderByDesc('points_balance'),
        };
        $page = $query->orderBy('id')->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1));
        $tierNames = LoyaltyTier::query()->pluck('name', 'id')->all();

        return response()->json([
            'data' => collect($page->items())->map(fn (Contact $c) => [
                'id' => $c->publicId(),
                'name' => $c->displayName(),
                'email' => ContactAccess::email($c->email),
                'points' => (int) $c->getAttribute('points_balance'),
                'wallet' => (string) $c->getAttribute('wallet_balance_ws'),
                'tier' => isset($tierNames[(string) $c->getAttribute('tier_id')]) ? ['id' => LoyaltyTier::ID_PREFIX.'_'.$c->getAttribute('tier_id'), 'name' => (string) $tierNames[(string) $c->getAttribute('tier_id')]] : null,
                'points_expiring' => (int) $c->getAttribute('points_expiring'),
                'points_expiring_at' => $c->getAttribute('points_expiring_at'),
                'orders_count' => (int) $c->getAttribute('orders_count'),
            ])->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** GET loyalty/contacts/{contact}?page=: saldos, nivel y libro de un contacto (pestaña del perfil). */
    public function contact(Request $request, string $contact, MemberStatus $status): JsonResponse
    {
        Gate::authorize('ws.viewAllContacts');
        $model = ContactAccess::find($contact);
        $program = LoyaltyProgram::query()->first();
        $state = $status->forContact($model->id);
        $page = LedgerEntry::query()->where('contact_id', $model->id)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'page', max(1, (int) $request->query('page', 1)));
        // Cómo terminó cada pendiente de la página (el libro no cambia la fila original).
        $pendingIds = collect($page->items())->filter(fn (LedgerEntry $e) => $e->status === 'pending')->pluck('id')->all();
        $children = $pendingIds === [] ? collect() : LedgerEntry::query()->whereIn('parent_id', $pendingIds)->get()->groupBy('parent_id');
        $settled = [];
        foreach ($pendingIds as $id) {
            $kids = $children->get($id, collect());
            $parent = collect($page->items())->firstWhere('id', $id);
            $settled[$id] = $kids->contains('status', 'confirmed') ? 'confirmed'
                : ($parent instanceof LedgerEntry && Decimal::equals(Balances::pendingRemaining($parent, array_values($kids->all())), '0') ? 'reversed' : null);
        }

        return response()->json([
            'program' => $program !== null ? ['status' => $program->status, 'currency_name' => $program->currency_name] : null,
            'currency' => $this->current->get()->currency,
            'balances' => $state['balances'],
            'tier' => $state['tier'],
            'ledger' => collect($page->items())->map(fn (LedgerEntry $e) => LoyaltyPresenter::entry($e, $settled[$e->id] ?? null))->values(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'rewards' => $program !== null ? LoyaltyReward::query()->where('program_id', $program->id)->where('status', 'active')->orderBy('points_cost')->get()
                ->map(fn (LoyaltyReward $r) => LoyaltyPresenter::reward($r))->values() : [],
        ]);
    }

    /** POST loyalty/contacts/{contact}/adjust {kind, direction, amount, reason, note}: ajuste manual auditado. */
    public function adjust(Request $request, string $contact, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = ContactAccess::find($contact);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['points', 'wallet'])],
            'direction' => ['required', Rule::in(['add', 'remove'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'reason' => ['required', Rule::in(['manual', 'refund', 'goodwill', 'correction'])],
            'note' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        if ($data['kind'] === 'points' && floor((float) $data['amount']) !== (float) $data['amount']) {
            throw ValidationException::withMessages(['amount' => __('Los puntos son números enteros.')]);
        }
        $key = 'manual:'.Str::ulid();
        try {
            if ($data['kind'] === 'points') {
                $program = LoyaltyProgram::query()->first();
                if ($program === null) {
                    throw ValidationException::withMessages(['kind' => __('Primero configura el programa de puntos.')]);
                }
                $points = (int) $data['amount'];
                if ($data['direction'] === 'add') {
                    // Un ajuste manual se da aunque el programa esté en pausa.
                    $entry = $this->ledger->manualPoints($model->id, $points, $data['reason'], $key);
                } else {
                    $entry = $this->ledger->debitPoints($model->id, $points, $data['reason'], $key, 'manual', null);
                }
            } elseif ($data['direction'] === 'add') {
                $entry = LedgerEntry::query()->findOrFail($this->ledger->creditWallet($model->id, (string) $data['amount'], $data['reason'], $key, 'manual'));
            } else {
                $entry = $this->ledger->debitWallet($model->id, (string) $data['amount'], $data['reason'], $key, 'manual') ?? throw new RuntimeException(__('El saldo no alcanza.'));
            }
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        $audit->handle('loyalty.adjusted', $entry, [
            'contact' => $model->publicId(), 'kind' => $data['kind'], 'direction' => $data['direction'],
            'amount' => (string) $data['amount'], 'reason' => $data['reason'], 'note' => $data['note'],
        ], $this->current->id());

        return response()->json(LoyaltyPresenter::entry($entry), 201);
    }

    private function programOrCreate(): LoyaltyProgram
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

    /** @return array<string, mixed> */
    private function rewardData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(LoyaltyReward::KINDS)],
            'points_cost' => ['required', 'integer', 'min:1', 'max:10000000'],
            'value' => ['required_unless:kind,free_shipping', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'config.min_order' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'config.expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'config.product_id' => ['required_if:kind,free_product', 'nullable', 'string', 'max:64'],
        ]);
        $value = (float) ($data['value'] ?? 0);
        if ($data['kind'] !== 'free_shipping' && $value <= 0) {
            throw ValidationException::withMessages(['value' => __('Indica el valor de la recompensa.')]);
        }
        if ($data['kind'] === 'coupon_percent' && $value > 100) {
            throw ValidationException::withMessages(['value' => __('El porcentaje no puede pasar de 100.')]);
        }
        $config = array_filter([
            'min_order' => isset($data['config']['min_order']) && (float) $data['config']['min_order'] > 0 ? number_format((float) $data['config']['min_order'], 2, '.', '') : null,
            'expires_in_days' => isset($data['config']['expires_in_days']) ? (int) $data['config']['expires_in_days'] : null,
            'product_id' => $data['kind'] === 'free_product' ? (string) ($data['config']['product_id'] ?? '') : null,
        ], fn ($v) => $v !== null && $v !== '');
        if ($data['kind'] === 'free_product' && ! DB::table('products')->where('workspace_id', $this->current->id())->where('external_id', $config['product_id'] ?? '-')->exists()) {
            throw ValidationException::withMessages(['config.product_id' => __('Ese producto no está en el catálogo sincronizado.')]);
        }

        return [
            'name' => trim($data['name']),
            'kind' => $data['kind'],
            'points_cost' => (int) $data['points_cost'],
            'value' => number_format($data['kind'] === 'free_shipping' ? 0 : $value, 2, '.', ''),
            'config' => $config === [] ? null : $config,
        ];
    }

    private function findReward(string $publicId): LoyaltyReward
    {
        $id = PrefixedId::decode(LoyaltyReward::ID_PREFIX, $publicId);

        return LoyaltyReward::query()->find($id ?? '-') ?? abort(404);
    }

    /** @param Builder<Contact> $query */
    private function search(Builder $query, string $q): void
    {
        $wsId = $this->current->id();
        $query->where(function (Builder $w) use ($q, $wsId): void {
            $w->where('email_bidx', BlindIndex::email($wsId, $q));
            $digits = preg_replace('/[^0-9+]/', '', $q) ?? '';
            if (strlen($digits) >= 7) {
                $e164 = Phone::toE164($digits, $this->current->get()->country);
                if ($e164 !== null) {
                    $w->orWhere('phone_bidx', BlindIndex::phone($wsId, $e164));
                }
            }
            $word = mb_strtolower(explode(' ', $q)[0]);
            if (mb_strlen($word) >= 2) {
                $w->orWhereJsonContains('name_prefix_bidx', substr(BlindIndex::value($wsId, 'name', mb_substr($word, 0, 6)), 0, 16));
            }
        });
    }
}
