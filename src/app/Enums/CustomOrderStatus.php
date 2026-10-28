<?php

namespace App\Enums;

/**
 * M-04 «الطلب المخصص». P4 implements only the opening (T00 → in_progress); the other transitions are declared so
 * the record-state guard refuses anything the machine does not define, and later phases add their actions.
 */
enum CustomOrderStatus: string
{
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case ExecutionFinished = 'execution_finished';
    case CancellationPending = 'cancellation_pending';
    case Cancelled = 'cancelled';
    case Closed = 'closed';

    /**
     * Transition code => allowed source states and target (T05/T06 return to a recorded state, so `to` is null).
     *
     * @return array<string, array{from: list<self>, to: self|null}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::InProgress], 'to' => self::Paused],
            'T02' => ['from' => [self::Paused], 'to' => self::InProgress],
            'T03' => ['from' => [self::InProgress], 'to' => self::ExecutionFinished],
            'T04' => ['from' => [self::InProgress, self::Paused, self::ExecutionFinished], 'to' => self::CancellationPending],
            'T05' => ['from' => [self::CancellationPending], 'to' => null],
            'T06' => ['from' => [self::CancellationPending], 'to' => null],
            'T07' => ['from' => [self::ExecutionFinished, self::Cancelled], 'to' => self::Closed],
            'T08' => ['from' => [self::ExecutionFinished, self::Closed], 'to' => self::InProgress],
        ];
    }

    /**
     * Needs and unapproved lines can change (P-17) while the order is running or paused.
     */
    public function isEditable(): bool
    {
        return $this === self::InProgress || $this === self::Paused;
    }

    public function isFinal(): bool
    {
        return $this === self::Cancelled || $this === self::Closed;
    }
}
