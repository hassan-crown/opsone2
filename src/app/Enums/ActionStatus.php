<?php

namespace App\Enums;

/**
 * M-27 «نتيجة إجراء المرحلة».
 */
enum ActionStatus: string
{
    case NotRequired = 'not_required';
    case Unavailable = 'unavailable';
    case Ready = 'ready';
    case AwaitingResult = 'awaiting_result';
    case Verified = 'verified';
    case Allowed = 'allowed';
    case NeedsRework = 'needs_rework';

    /**
     * T06 and T07 choose among targets, so `to` is null.
     *
     * @return array<string, array{from: list<self>, to: self|null}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Unavailable], 'to' => self::Ready],
            'T02' => ['from' => [self::Ready], 'to' => self::AwaitingResult],
            'T03' => ['from' => [self::Ready, self::AwaitingResult], 'to' => self::Verified],
            'T04' => ['from' => [self::AwaitingResult, self::Ready], 'to' => self::Allowed],
            'T05' => ['from' => [self::Verified, self::Allowed], 'to' => self::NeedsRework],
            'T06' => ['from' => [self::NeedsRework], 'to' => null],
            'T07' => ['from' => [self::NotRequired], 'to' => null],
            'T08' => ['from' => [self::Ready], 'to' => self::NotRequired],
        ];
    }

    /**
     * The action no longer holds its stage back: its result is in, an allowance covers it, or it does not apply.
     */
    public function satisfies(): bool
    {
        return in_array($this, [self::Verified, self::Allowed, self::NotRequired], true);
    }
}
