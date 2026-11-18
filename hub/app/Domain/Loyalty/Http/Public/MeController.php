<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Http\Public;

use App\Domain\Commerce\Projectors\FxConverter;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Identity\SiteToken;
use App\Domain\Loyalty\Actions\RedeemReward;
use App\Domain\Loyalty\Contracts\MeSection;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyReward;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Loyalty\Queries\MemberStatus;
use App\Domain\Loyalty\Support\MoneyText;
use App\Domain\Loyalty\Support\ProgramExplainer;
use App\Domain\Sites\Models\Site;
use App\Support\Ids\PrefixedId;
use App\Support\Money\Decimal;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * «Mis recompensas» y el programa en la tienda (api-connector.md §8.3, M13):
 *
 * - `GET /p/{key}/me?token=`: puntos, nivel, saldo, recompensas y las secciones
 *   de otros módulos (MeSection). Privado y **solo con un igrUser.token
 *   válido**; si no, 401 sin ningún dato.
 * - `POST /p/{key}/me/redeem` `{token, reward_id}`: canje por cupón o saldo.
 * - `GET /p/{key}/points?product_id=&price=&cart_total=`: «Gana N puntos», con
 *   la regla base (sin el multiplicador del nivel). Público, en caché 5 min.
 * - `GET /p/{key}/program`: la página explicativa del programa. Pública, 5 min.
 */
final class MeController
{
    public function __construct(private readonly PublicSite $sites, private readonly CurrentWorkspace $current) {}

    public function show(Request $request, string $publicKey, MemberStatus $status): JsonResponse
    {
        [$site, $workspace] = $this->sites->resolve($publicKey) ?? [null, null];
        if ($site === null || $workspace === null) {
            return response()->json(['ok' => false, 'error' => ['code' => 'unknown_site']], 404);
        }
        if ($this->sites->limited($request, 'me', 60)) {
            return $this->sites->error($request, $site, 429, 'rate_limited');
        }
        $ref = $this->ref($site, $request->query('token'));
        if ($ref === null) {
            return $this->sites->error($request, $site, 401, 'invalid_token');
        }

        return $this->current->run($workspace, function () use ($request, $site, $workspace, $ref, $status): JsonResponse {
            $contact = $this->contact($site, $ref);
            $program = LoyaltyProgram::query()->first();
            $state = $contact !== null ? $status->forContact($contact->id) : null;
            $balances = $state['balances'] ?? ['points' => 0, 'pending' => 0, 'wallet' => '0.00', 'expiring' => 0, 'expiring_at' => null];
            $tier = $state['tier'] ?? null;
            $active = $program !== null && $program->isActive();
            $body = [
                'program' => ['active' => $active, 'currency_name' => $program?->currency_name],
                'points' => [
                    'balance' => $balances['points'],
                    'pending' => $balances['pending'],
                    'currency_name' => $program !== null ? $program->currency_name : __('Puntos'),
                    'expiring' => $balances['expiring_at'] !== null && $balances['expiring'] > 0 ? ['points' => $balances['expiring'], 'at' => $balances['expiring_at']] : null,
                ],
                'tier' => $tier !== null && ($tier['name'] !== null || $tier['next_name'] !== null) ? [
                    'name' => $tier['name'], 'next_name' => $tier['next_name'], 'progress_pct' => $tier['progress_pct'], 'benefits' => $tier['benefits'],
                ] : null,
                'wallet' => ['balance' => Decimal::of($balances['wallet']), 'currency' => $workspace->currency],
                'rewards' => $active ? LoyaltyReward::query()->where('program_id', $program->id)->where('status', 'active')->orderBy('points_cost')->get()
                    ->map(fn (LoyaltyReward $r) => ['id' => $r->publicId(), 'name' => $r->name, 'points' => $r->points_cost, 'kind' => $r->kind, 'affordable' => $balances['points'] >= $r->points_cost])->values()->all() : [],
            ];
            // Secciones de otros módulos (p. ej. `referral` de M14). Una que falle no tumba la respuesta.
            foreach (app()->tagged(MeSection::TAG) as $section) {
                if (! $section instanceof MeSection) {
                    continue;
                }
                try {
                    $data = $section->forContact($site, $contact);
                    if ($data !== null) {
                        $body[$section->key()] = $data;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }

            return response()->json($body)->withHeaders($this->sites->cors($request, $site) + ['Cache-Control' => 'private, no-store']);
        });
    }

    public function redeem(Request $request, string $publicKey, RedeemReward $redeem): JsonResponse
    {
        [$site, $workspace] = $this->sites->resolve($publicKey) ?? [null, null];
        if ($site === null || $workspace === null) {
            return response()->json(['ok' => false, 'error' => ['code' => 'unknown_site']], 404);
        }
        if ($this->sites->limited($request, 'redeem', 10)) {
            return $this->sites->error($request, $site, 429, 'rate_limited');
        }
        $body = PublicSite::body($request);
        $ref = $this->ref($site, is_string($body['token'] ?? null) ? $body['token'] : null);
        if ($ref === null) {
            return $this->sites->error($request, $site, 401, 'invalid_token');
        }

        return $this->current->run($workspace, function () use ($request, $site, $workspace, $ref, $body, $redeem): JsonResponse {
            $contact = $this->contact($site, $ref);
            $rewardId = PrefixedId::decode(LoyaltyReward::ID_PREFIX, is_string($body['reward_id'] ?? null) ? $body['reward_id'] : null);
            $reward = $rewardId !== null ? LoyaltyReward::query()->find($rewardId) : null;
            if ($contact === null || $reward === null) {
                return $this->sites->error($request, $site, 422, $reward === null ? 'unknown_reward' : 'insufficient_points', $reward === null ? __('Esta recompensa no está disponible.') : __('No tienes puntos suficientes.'));
            }
            try {
                $result = $redeem->handle($contact->id, $reward, $site->id);
            } catch (RuntimeException $e) {
                return $this->sites->error($request, $site, 422, 'not_redeemed', $e->getMessage());
            }
            $out = ['ok' => true, 'balance' => $result['balance']];
            if ($result['coupon'] !== null) {
                $out['coupon'] = [
                    'code' => $result['coupon']->code,
                    'description' => $reward->name,
                    'expires_at' => $result['coupon']->expires_at?->setTimezone($site->timezone ?: $workspace->timezone)->toDateString(),
                ];
            }
            if ($result['wallet'] !== null) {
                $out['wallet'] = ['balance' => $result['wallet'], 'currency' => $workspace->currency];
            }

            return response()->json($out)->withHeaders($this->sites->cors($request, $site) + ['Cache-Control' => 'private, no-store']);
        });
    }

    public function points(Request $request, string $publicKey, FxConverter $fx): JsonResponse
    {
        [$site, $workspace] = $this->sites->resolve($publicKey) ?? [null, null];
        if ($site === null || $workspace === null) {
            return response()->json(['points' => 0, 'label' => null], 404);
        }
        $productId = is_string($request->query('product_id')) ? mb_substr($request->query('product_id'), 0, 64) : null;
        $price = is_numeric($request->query('price')) ? (float) $request->query('price') : null;
        $cart = is_numeric($request->query('cart_total')) ? (float) $request->query('cart_total') : null;
        $key = 'igr:loyalty:points:'.$site->id.':'.md5(json_encode([$productId, $price, $cart]) ?: '');
        $body = Cache::remember($key, 300, fn () => $this->current->run($workspace, function () use ($site, $workspace, $productId, $price, $cart, $fx): array {
            $program = LoyaltyProgram::query()->first();
            if ($program === null || ! $program->isActive()) {
                return ['points' => 0, 'label' => null];
            }
            $rules = $program->rules();
            $categories = [];
            if ($productId !== null) {
                $product = DB::table('products')->where('workspace_id', $workspace->id)->where('site_id', $site->id)->where('external_id', $productId)->first(['price', 'category_ids']);
                $categories = $product !== null ? array_map('strval', (array) json_decode((string) $product->category_ids, true)) : [];
                $price ??= $product !== null ? (float) $product->price : null;
            }
            $amount = $cart ?? $price ?? 0.0;
            $currency = $site->currency ?: $workspace->currency;
            if ($currency !== $workspace->currency) {
                $rate = $fx->rate($currency, $workspace->currency, now());
                $amount = $rate !== null ? $amount * (float) $rate : 0.0;
            }
            $multiplier = $rules->categoryMultiplier($categories) * $rules->campaignMultiplier(now(), $workspace->timezone ?: 'America/Bogota');
            $points = (int) floor($rules->basePoints(number_format($amount, 2, '.', '')) * $multiplier + 1e-9);

            return ['points' => $points, 'label' => $points > 0 ? __('Gana :points :name', ['points' => MoneyText::points($points, $workspace->locale ?: 'es-CO'), 'name' => $program->currency_name]) : null];
        }));

        return response()->json($body)->withHeaders($this->sites->cors($request, $site) + ['Cache-Control' => 'public, max-age=300']);
    }

    public function program(Request $request, string $publicKey): JsonResponse
    {
        [$site, $workspace] = $this->sites->resolve($publicKey) ?? [null, null];
        if ($site === null || $workspace === null) {
            return response()->json(['active' => false], 404);
        }
        $body = Cache::remember('igr:loyalty:program:'.$site->id, 300, fn () => $this->current->run($workspace, function () use ($workspace): array {
            $program = LoyaltyProgram::query()->first();
            if ($program === null || ! $program->isActive()) {
                return ['active' => false];
            }
            $tiers = LoyaltyTier::query()->where('program_id', $program->id)->orderBy('rank')->get();
            $rewards = LoyaltyReward::query()->where('program_id', $program->id)->where('status', 'active')->orderBy('points_cost')->get();

            return [
                'active' => true,
                'currency_name' => $program->currency_name,
                'explainer' => ProgramExplainer::build($program, $tiers, $rewards, $workspace),
                'tiers' => $tiers->map(fn (LoyaltyTier $t) => ['name' => $t->name, 'benefits' => ProgramExplainer::benefits($t)])->values()->all(),
                'rewards' => $rewards->map(fn (LoyaltyReward $r) => ['id' => $r->publicId(), 'name' => $r->name, 'points' => $r->points_cost, 'kind' => $r->kind])->values()->all(),
            ];
        }));

        return response()->json($body)->withHeaders($this->sites->cors($request, $site) + ['Cache-Control' => 'public, max-age=300']);
    }

    /** customer_ref («wc:77») de un igrUser.token válido para este sitio. */
    private function ref(Site $site, mixed $token): ?string
    {
        $payload = SiteToken::verify($site, is_string($token) ? $token : null);
        $ref = $payload['ref'] ?? null;

        return is_string($ref) && $ref !== '' && strlen($ref) <= 80 ? $ref : null;
    }

    private function contact(Site $site, string $ref): ?Contact
    {
        $id = ContactIdentity::query()->where('type', 'customer_ref')->where('value', $site->id.':'.$ref)->value('contact_id');
        if (! is_string($id)) {
            return null;
        }
        $contact = Contact::query()->whereKey($id)->first();
        for ($i = 0; $contact !== null && $contact->getAttribute('merged_into_id') !== null && $i < 10; $i++) {
            $contact = Contact::query()->whereKey((string) $contact->getAttribute('merged_into_id'))->first();
        }

        return $contact;
    }
}
