<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Http;

use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Referrals\Actions\SaveReferralProgram;
use App\Domain\Referrals\Models\ReferralProgram;
use App\Domain\Referrals\Support\ProgramSettings;
use App\Domain\Referrals\Support\ReferralCodes;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Programa de referidos (modulos.md M14). Ver: viewDashboards; cambiarlo:
 * manageMarketing (queda en la auditoría).
 */
final class ReferralProgramController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET referrals/program */
    public function show(LoyaltyLedger $ledger): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $program = ReferralProgram::query()->first();

        return response()->json($this->present($program ?? $this->draft(), $program !== null, $ledger));
    }

    /** PUT referrals/program */
    public function update(Request $request, LoyaltyLedger $ledger, SaveReferralProgram $save): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'status' => ['required', Rule::in(['draft', 'active', 'paused'])],
            'friend_reward' => ['required', 'array'],
            'friend_reward.kind' => ['required', Rule::in(['percent', 'fixed_cart', 'free_shipping'])],
            'friend_reward.amount' => ['nullable', 'numeric', 'min:0', 'max:100000000', Rule::requiredIf(fn () => $request->input('friend_reward.kind') !== 'free_shipping')],
            'friend_reward.expires_days' => ['required', 'integer', 'min:1', 'max:365'],
            'friend_reward.minimum_amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'referrer_reward' => ['required', 'array'],
            'referrer_reward.type' => ['required', Rule::in(['points', 'wallet', 'coupon'])],
            'referrer_reward.points' => ['nullable', 'integer', 'min:1', 'max:1000000', Rule::requiredIf(fn () => $request->input('referrer_reward.type') === 'points')],
            'referrer_reward.amount' => ['nullable', 'numeric', 'min:0', 'max:100000000', Rule::requiredIf(fn () => in_array($request->input('referrer_reward.type'), ['wallet', 'coupon'], true) && $request->input('referrer_reward.coupon_kind') !== 'free_shipping')],
            'referrer_reward.coupon_kind' => ['nullable', Rule::in(['percent', 'fixed_cart', 'free_shipping']), Rule::requiredIf(fn () => $request->input('referrer_reward.type') === 'coupon')],
            'referrer_reward.expires_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'rules' => ['required', 'array'],
            'rules.min_order' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'rules.monthly_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'rules.return_days' => ['required', 'integer', 'min:0', 'max:90'],
            'rules.qualify_on' => ['required', Rule::in(['completed', 'paid'])],
            'rules.review_suspicious' => ['required', 'boolean'],
            'rules.signal_window_days' => ['nullable', 'integer', 'min:1', 'max:180'],
            'share_text' => ['nullable', 'string', 'max:500'],
        ]);
        $friend = $data['friend_reward'];
        $referrer = $data['referrer_reward'];
        $errors = [];
        if ($friend['kind'] === 'percent' && (float) ($friend['amount'] ?? 0) > 100) {
            $errors['friend_reward.amount'] = __('El porcentaje no puede pasar de 100.');
        }
        if ($referrer['type'] === 'coupon' && ($referrer['coupon_kind'] ?? null) === 'percent' && (float) ($referrer['amount'] ?? 0) > 100) {
            $errors['referrer_reward.amount'] = __('El porcentaje no puede pasar de 100.');
        }
        $share = isset($data['share_text']) ? trim((string) $data['share_text']) : '';
        if ($share !== '' && ! str_contains($share, '{enlace}') && ! str_contains($share, '{codigo}')) {
            $errors['share_text'] = __('El texto debe llevar {enlace} o {codigo} para que el amigo pueda usarlo.');
        }
        if ($data['status'] === 'active' && $referrer['type'] === 'points' && ! $ledger->isActive()) {
            $errors['referrer_reward.type'] = __('Para dar puntos, activa primero el programa de puntos en Fidelización. También puedes dar saldo a favor o un cupón.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $program = $save->handle($data['status'], $friend, $referrer, $data['rules'], $share);

        return response()->json($this->present($program, true, $ledger));
    }

    private function draft(): ReferralProgram
    {
        return new ReferralProgram(['status' => 'draft', 'share_text' => null] + SaveReferralProgram::recommended($this->current->get(), false));
    }

    /** @return array<string, mixed> */
    private function present(ReferralProgram $program, bool $exists, LoyaltyLedger $ledger): array
    {
        $settings = new ProgramSettings($program, $this->current->get());
        $site = Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->first();
        $example = $site !== null ? ReferralCodes::link($site, 'ANA7K2') : 'https://tienda.co/?ref=ANA7K2';

        return [
            'exists' => $exists,
            'id' => $exists ? $program->publicId() : null,
            'status' => $program->status,
            'friend_reward' => $settings->friend,
            'referrer_reward' => $settings->referrer,
            'rules' => $settings->rules,
            'share_text' => $program->share_text,
            'default_share_text' => __(ProgramSettings::DEFAULT_SHARE_TEXT),
            'labels' => [
                'friend_reward' => $settings->friendRewardLabel(),
                'referrer_reward' => $settings->referrerRewardLabel(),
                'share_preview' => $settings->shareText($example, 'ANA7K2', $site !== null ? ($site->name ?: $this->current->get()->name) : $this->current->get()->name),
                'example_link' => $example,
            ],
            'loyalty_active' => $ledger->isActive(),
            'currency' => $this->current->get()->currency,
        ];
    }
}
