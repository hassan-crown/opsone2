<?php

namespace App\Finance;

use App\Access\Exceptions\InvalidTransition;
use App\Enums\PaymentStatus;
use App\Enums\ProofStatus;
use App\Enums\StateMachine;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentProof;
use App\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * One transfer, one payment (SRS-INT-04). A trusted reference that already belongs to a payment refuses a second
 * one (`duplicate_reference`, also enforced by the unique `reference_key` under concurrency); a pending customer proof
 * of the same reference sends the person to confirm that proof instead (`matching_proof`). Mere similarity (same
 * customer and amount within a few days, no matching reference) is only a warning: a real payment is never blocked
 * for looking like another one.
 */
final class PaymentRegistry
{
    public const SIMILAR_DAYS = 3;

    public static function byReference(?string $referenceKey): ?Payment
    {
        return $referenceKey === null ? null : Payment::query()->where('reference_key', $referenceKey)->first();
    }

    public static function ensureNewReference(?string $referenceKey, string $transition = 'T01'): void
    {
        if (self::byReference($referenceKey) !== null) {
            throw new InvalidTransition(StateMachine::M11, $transition, 'duplicate_reference');
        }
    }

    public static function pendingProof(?string $referenceKey): ?PaymentProof
    {
        return $referenceKey === null ? null : self::openProofs()->where('reference_key', $referenceKey)->first();
    }

    /**
     * Proofs awaiting a decision: under review or sent back, and not replaced by a newer proof.
     *
     * @return Builder<PaymentProof>
     */
    public static function openProofs(): Builder
    {
        return PaymentProof::query()
            ->whereIn('payment_proofs.status', [ProofStatus::UnderReview->value, ProofStatus::NeedsCorrection->value])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_proofs as successor')->whereColumn('successor.replaces_id', 'payment_proofs.id'));
    }

    /**
     * @return Collection<int, Payment>
     */
    public static function similar(int $customerId, Money $amount, string $paidOn): Collection
    {
        $day = CarbonImmutable::parse($paidOn);

        return Payment::query()->where('customer_id', $customerId)->where('amount_minor', $amount->minor)
            ->whereIn('status', [PaymentStatus::Confirmed->value, PaymentStatus::PendingConfirmation->value, PaymentStatus::Unresolved->value])
            ->whereBetween('paid_on', [$day->subDays(self::SIMILAR_DAYS)->toDateString(), $day->addDays(self::SIMILAR_DAYS)->toDateString()])
            ->orderByDesc('id')->limit(5)->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes, string $transition = 'T01'): Payment
    {
        try {
            return Payment::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            // Another channel recorded the same transfer at the same moment: it won; nothing else was written.
            throw new InvalidTransition(StateMachine::M11, $transition, 'duplicate_reference');
        }
    }
}
