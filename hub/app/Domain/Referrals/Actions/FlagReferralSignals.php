<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Referrals\Support\Fingerprints;
use App\Domain\Referrals\Support\Programs;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Vuelve a comparar a quien refiere con su amigo cuando el amigo deja huellas nuevas
 * (su pedido: IP y destinatario). Las señales nuevas mandan el referido a revisión.
 * Lo llaman la atribución y el listener de pedidos: el que llegue último ve todas
 * las huellas. Debe correr dentro del contexto del espacio de trabajo.
 */
final class FlagReferralSignals
{
    public function __construct(private readonly Programs $programs, private readonly Fingerprints $fingerprints, private readonly CurrentWorkspace $current) {}

    public function forFriend(Referral $referral, string $stage = 'order'): void
    {
        $settings = $this->programs->settings();
        if ($settings === null || ! $referral->isOpen() || $referral->friend_contact_id === null) {
            return;
        }
        $referrer = Contact::query()->find($referral->referrer_contact_id);
        $friend = Contact::query()->find($referral->friend_contact_id);
        if ($referrer === null || $friend === null) {
            return;
        }
        $before = $referral->signals();
        $referral->addSignals($this->fingerprints->compare($this->current->id(), $referrer, $friend, [], $settings->signalWindowDays()), $stage);
        if (array_diff($referral->signals(), $before) === []) {
            return;
        }
        if ($settings->reviewSuspicious() && $referral->review_status === null) {
            $referral->review_status = Referral::PENDING_REVIEW;
        }
        $referral->save();
    }
}
