<?php

namespace App\Agreements;

use App\Access\TransitionRecorder;
use App\Enums\DiscountDecisionStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\DiscountDecision;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;

/**
 * Whether priced content may go to the customer with its discount (AGR-02, M-08-T01 «خصم معتمد»): no discount, a
 * discount within the sender's own limit, or an approved decision on exactly this content. Also keeps decisions in
 * step with the content: when priced content changes, an approved decision is superseded (M-26-T04) and an open
 * request on the old content is cancelled (T03) — a decision never carries over to different content.
 */
final class DiscountCheck
{
    public const NONE = 'none';

    public const WITHIN_LIMIT = 'within_limit';

    public const APPROVED = 'approved';

    public const REQUESTED = 'requested';

    public const NEEDS_DECISION = 'needs_decision';

    public const INCOMPLETE = 'incomplete';

    public function __construct(private readonly TransitionRecorder $transitions) {}

    /**
     * @param  array<string, mixed>  $content  priced content (QuoteInput::priced)
     * @return array{state: string, limit_bp: int|null, share_bp: int, over_minor: int, decision_id: int|null}
     */
    public static function state(User $user, string $subjectType, int $subjectId, array $content, ?Order $order = null): array
    {
        $limit = DiscountLimits::of($user, 'custom_bp', $order);
        $totals = $content['totals'] ?? [];
        if (! ($content['complete'] ?? false)) {
            return ['state' => self::INCOMPLETE, 'limit_bp' => $limit, 'share_bp' => 0, 'over_minor' => 0, 'decision_id' => null];
        }

        $base = Money::of((int) $totals['base_minor']);
        $discount = Money::of((int) $totals['discount_minor']);
        $share = DiscountLimits::share($discount, $base);
        $over = $limit === null ? 0 : max(0, $discount->minus($base->percent($limit))->minor);
        $result = ['limit_bp' => $limit, 'share_bp' => $share, 'over_minor' => $over, 'decision_id' => null];
        if ($discount->isZero()) {
            return ['state' => self::NONE] + $result;
        }
        if (! DiscountLimits::exceeds($discount, $base, $limit)) {
            return ['state' => self::WITHIN_LIMIT] + $result;
        }

        $hash = ContentHash::of(self::priceKey($content));
        $decision = DiscountDecision::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('content_hash', $hash)->whereIn('status', [DiscountDecisionStatus::Approved->value, DiscountDecisionStatus::Requested->value])
            ->latest('id')->first();
        if ($decision !== null) {
            return ['state' => $decision->status === DiscountDecisionStatus::Approved ? self::APPROVED : self::REQUESTED, 'decision_id' => $decision->id] + $result;
        }

        return ['state' => self::NEEDS_DECISION] + $result;
    }

    /**
     * What a discount decision is bound to: the priced lines, discount and totals (not the terms text).
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function priceKey(array $content): array
    {
        return ['lines' => $content['lines'] ?? [], 'discount' => $content['discount'] ?? [], 'vat' => $content['vat'] ?? [], 'totals' => $content['totals'] ?? []];
    }

    /**
     * Content of a subject changed: earlier decisions no longer apply to it.
     *
     * @param  array<string, mixed>  $content
     */
    public function contentChanged(string $subjectType, int $subjectId, array $content, ?User $actor): void
    {
        $hash = ContentHash::of(self::priceKey($content));
        $stale = DiscountDecision::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('content_hash', '!=', $hash)
            ->whereIn('status', [DiscountDecisionStatus::Approved->value, DiscountDecisionStatus::Requested->value])->get();

        foreach ($stale as $decision) {
            [$code, $to] = $decision->status === DiscountDecisionStatus::Approved
                ? ['T04', DiscountDecisionStatus::Superseded]
                : ['T03', DiscountDecisionStatus::Cancelled];
            $from = $decision->status;
            $decision->forceFill(['status' => $to, 'superseded_at' => $to === DiscountDecisionStatus::Superseded ? now() : null, 'lock_version' => $decision->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M26, $code, $decision, $from, $to, $actor, TransitionRecorder::SOURCE_SYSTEM, context: [
                'order_id' => $decision->order_id, 'reason' => 'content_changed',
            ]);
        }
    }
}
