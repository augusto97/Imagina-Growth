<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Banners;

use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Support\CartRestoreLink;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Recommendations\Models\OnsiteBanner;
use App\Domain\Recommendations\Support\VisitorResolver;
use App\Domain\Sites\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * El banner personalizado de un visitante (api-connector.md §8.3 `GET /banner`).
 * Gana el primer banner activo del lugar (por prioridad) cuya audiencia
 * incluye a la persona.
 *
 * Datos personales: el nombre y los puntos solo se llenan con un `igrUser.token`
 * válido (sesión iniciada). Sin token se usa `text_public`, que no los lleva, y
 * el carrito pendiente solo cuenta si es de ese mismo navegador.
 */
final class ResolveBanner
{
    /** Variables que solo se llenan con sesión iniciada. */
    public const PRIVATE_VARIABLES = ['nombre', 'puntos'];

    public function __construct(private readonly VisitorResolver $visitors, private readonly LoyaltyLedger $ledger) {}

    /** @return array{id: string, text: string, cta: string|null, url: string|null, style: string}|null */
    public function handle(Site $site, string $placement, ?string $anonymousId, ?string $token): ?array
    {
        $anonymousId = VisitorResolver::cleanAnonymousId($anonymousId);
        $tokenContact = $this->visitors->fromToken($site, $token);
        $authenticated = $tokenContact !== null;
        $contactId = $tokenContact ?? $this->visitors->fromAnonymous($anonymousId);

        $banners = OnsiteBanner::query()->where('status', 'active')->where('placement', $placement)
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site->id))
            ->orderBy('priority')->orderBy('created_at')->limit(50)->get();
        foreach ($banners as $banner) {
            if (! $banner->isLive()) {
                continue;
            }
            $match = $this->matches($banner, $site, $contactId, $anonymousId, $authenticated);
            if ($match === null) {
                continue;
            }
            $template = $authenticated ? $banner->text : (string) $banner->text_public;
            if (trim($template) === '') {
                continue;
            }
            $vars = $match['vars'];
            if ($authenticated && $contactId !== null) {
                $vars['nombre'] = trim((string) Contact::query()->whereKey($contactId)->first(['id', 'first_name'])?->first_name);
            }

            return [
                'id' => $banner->publicId(),
                'text' => self::render($template, $vars),
                'cta' => $banner->cta,
                'url' => $banner->url ?: $match['url'],
                'style' => $banner->style,
            ];
        }

        return null;
    }

    /**
     * Llena {{variable}}. Las que no tienen valor quedan vacías.
     *
     * @param  array<string, string>  $vars
     */
    public static function render(string $text, array $vars): string
    {
        $out = (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn (array $m) => $vars[$m[1]] ?? '', $text);

        return trim((string) preg_replace('/\s{2,}/', ' ', $out));
    }

    /** @return array{vars: array<string, string>, url: string|null}|null */
    private function matches(OnsiteBanner $banner, Site $site, ?string $contactId, ?string $anonymousId, bool $authenticated): ?array
    {
        switch ($banner->audience) {
            case 'segment':
                $in = $contactId !== null && $banner->segment_id !== null
                    && DB::table('segment_memberships')->where('segment_id', $banner->segment_id)->where('contact_id', $contactId)->exists();

                return $in ? ['vars' => [], 'url' => null] : null;

            case 'points':
                if ($contactId === null || ! $this->ledger->isActive()) {
                    return null;
                }
                $balance = $this->ledger->pointsBalance($contactId);
                if ($balance < max(1, (int) $banner->min_points)) {
                    return null;
                }

                return ['vars' => $authenticated ? ['puntos' => number_format($balance, 0, ',', '.')] : [], 'url' => null];

            case 'pending_cart':
                $cart = Cart::query()->where('site_id', $site->id)->whereIn('status', ['active', 'abandoned'])->where('items_count', '>', 0)
                    ->where('last_activity_at', '>=', now()->subDays((int) config('igr-commerce.banners.pending_cart_days', 7)))
                    ->where(function ($q) use ($authenticated, $contactId, $anonymousId): void {
                        if ($anonymousId !== null) {
                            $q->orWhere('anonymous_id', $anonymousId);
                        }
                        if ($authenticated && $contactId !== null) {
                            $q->orWhere('contact_id', $contactId);
                        }
                    })
                    ->when($anonymousId === null && ! $authenticated, fn ($q) => $q->whereRaw('1 = 0'))
                    ->orderByDesc('last_activity_at')->first();
                if ($cart === null) {
                    return null;
                }

                return ['vars' => ['carrito_items' => (string) $cart->items_count], 'url' => CartRestoreLink::for($cart)];

            case 'at_risk':
                $segment = $contactId !== null ? DB::table('contacts')->where('id', $contactId)->value('rfm_segment') : null;
                $risky = (array) config('igr-commerce.banners.at_risk_segments', ['at_risk', 'cant_lose']);

                return is_string($segment) && in_array($segment, $risky, true) ? ['vars' => [], 'url' => null] : null;
        }

        return null;
    }
}
