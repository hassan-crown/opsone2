<?php

namespace App\Enums;

/**
 * M-06 «مرحلة التشغيل».
 */
enum StageStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case CompletedNeedsCorrection = 'completed_needs_correction';

    /**
     * T06 is not in «الحالات والانتقالات» v1.1: domain 03 says the correction marker is removed once the correction
     * is done, so the engine returns the stage to completed (ADR-0012, reported as a documentation gap).
     *
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::NotStarted], 'to' => self::InProgress],
            'T02' => ['from' => [self::InProgress], 'to' => self::Waiting],
            'T03' => ['from' => [self::Waiting], 'to' => self::InProgress],
            'T04' => ['from' => [self::InProgress, self::Waiting], 'to' => self::Completed],
            'T05' => ['from' => [self::Completed], 'to' => self::CompletedNeedsCorrection],
            'T06' => ['from' => [self::CompletedNeedsCorrection], 'to' => self::Completed],
        ];
    }

    public function isActive(): bool
    {
        return $this === self::InProgress || $this === self::Waiting;
    }

    public function isDone(): bool
    {
        return $this === self::Completed || $this === self::CompletedNeedsCorrection;
    }
}
