<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Contracts\MeSection;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Sección `referral` de «Mis recompensas» (`GET /p/{public_key}/me`, api-connector.md
 * §8.3): código, enlace, texto para compartir y las dos recompensas. El código se
 * crea la primera vez. Null si el programa no está activo o la persona aún no está
 * en el hub. Se registra en M13 con la etiqueta MeSection::TAG. Debe correr dentro
 * del contexto del espacio de trabajo.
 */
final class ReferralMeSection implements MeSection
{
    public function __construct(
        private readonly Programs $programs,
        private readonly ReferralCodes $codes,
        private readonly CurrentWorkspace $current,
    ) {}

    public function key(): string
    {
        return 'referral';
    }

    /** @return array{code: string, link: string, share_text: string, friend_reward: string, referrer_reward: string}|null */
    public function forContact(Site $site, ?Contact $contact): ?array
    {
        $settings = $this->programs->active();
        if ($settings === null || $contact === null) {
            return null;
        }
        $code = $this->codes->forContact($contact);
        $link = ReferralCodes::link($site, $code);

        return [
            'code' => $code,
            'link' => $link,
            'share_text' => $settings->shareText($link, $code, $site->name ?: $this->current->get()->name),
            'friend_reward' => $settings->friendRewardLabel(),
            'referrer_reward' => $settings->referrerRewardLabel(),
        ];
    }
}
