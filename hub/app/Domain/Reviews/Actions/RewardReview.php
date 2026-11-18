<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Actions;

use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Reviews\Models\Review;
use Throwable;

/**
 * Recompensa por reseña (M15) en el libro de puntos de M13: `review` por dejarla
 * y `review_media` (extra) si trae foto o video. Los puntos de cada motivo los
 * define el programa de fidelización (LoyaltyLedger::rulePoints, en
 * Fidelización), no los ajustes de reseñas. Solo para compradores
 * verificados que llegaron por una solicitud. Al recibirla, los puntos quedan
 * pendientes; se confirman al publicarla y se revierten si se rechaza. Todo es
 * idempotente por reseña (`review:{id}` y `review_media:{id}`): dar dos veces la
 * misma recompensa devuelve la entrada original.
 */
final class RewardReview
{
    public const REF_TYPE = 'review';

    public function __construct(private readonly LoyaltyLedger $ledger) {}

    /**
     * Puntos que da una reseña según el programa (para mostrarlos en el formulario).
     *
     * @return array{review: int, media: int}
     */
    public function preview(): array
    {
        if (! $this->ledger->isActive()) {
            return ['review' => 0, 'media' => 0];
        }

        return ['review' => max(0, $this->ledger->rulePoints('review')), 'media' => max(0, $this->ledger->rulePoints('review_media'))];
    }

    /** Al recibirla: pendiente, o confirmada si ya quedó publicada. */
    public function onSubmitted(Review $review): void
    {
        $this->earn($review, pending: $review->status !== 'published');
    }

    public function onPublished(Review $review): void
    {
        if ($this->earn($review, pending: false)) {
            $this->safely(fn () => $this->ledger->settle(self::REF_TYPE, $review->publicId(), true));
        }
    }

    public function onRejected(Review $review): void
    {
        if ($this->eligible($review)) {
            $this->safely(fn () => $this->ledger->settle(self::REF_TYPE, $review->publicId(), false));
        }
    }

    private function earn(Review $review, bool $pending): bool
    {
        if (! $this->eligible($review) || ! $this->ledger->isActive()) {
            return false;
        }
        $contactId = (string) $review->contact_id;
        // points = 0: el libro usa la regla del programa para ese motivo (0 = sin puntos).
        $this->safely(fn () => $this->ledger->earnPoints($contactId, 0, 'review', 'review:'.$review->id, self::REF_TYPE, $review->publicId(), $pending));
        if ($review->hasMedia()) {
            $this->safely(fn () => $this->ledger->earnPoints($contactId, 0, 'review_media', 'review_media:'.$review->id, self::REF_TYPE, $review->publicId(), $pending));
        }

        return true;
    }

    private function eligible(Review $review): bool
    {
        return $review->contact_id !== null && $review->source === 'request' && $review->verified_buyer;
    }

    /** Un fallo del libro no debe tumbar la reseña ni la moderación: se reporta. */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
