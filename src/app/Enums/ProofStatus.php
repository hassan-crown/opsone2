<?php

namespace App\Enums;

/**
 * M-10 «إثبات تحويل العميل». A proof is never money; only its confirmation (T01) links it to one payment.
 */
enum ProofStatus: string
{
    case UnderReview = 'under_review';
    case NeedsCorrection = 'needs_correction';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::UnderReview], 'to' => self::Confirmed],
            'T02' => ['from' => [self::UnderReview], 'to' => self::NeedsCorrection],
            // The replacement proof goes under review; the corrected one is kept (replaces_id).
            'T03' => ['from' => [self::NeedsCorrection], 'to' => self::UnderReview],
            'T04' => ['from' => [self::UnderReview, self::NeedsCorrection], 'to' => self::Cancelled],
        ];
    }

    public function isOpen(): bool
    {
        return $this === self::UnderReview || $this === self::NeedsCorrection;
    }
}
