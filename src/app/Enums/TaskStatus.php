<?php

namespace App\Enums;

/**
 * M-07 «المهمة».
 */
enum TaskStatus: string
{
    case Unassigned = 'unassigned';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * T06 keeps the state (`to` null); T00 is the implicit creation.
     *
     * @return array<string, array{from: list<self>, to: self|null}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Unassigned], 'to' => self::Assigned],
            'T02' => ['from' => [self::Assigned], 'to' => self::InProgress],
            'T03' => ['from' => [self::Assigned, self::InProgress], 'to' => self::Waiting],
            'T04' => ['from' => [self::Waiting], 'to' => self::InProgress],
            'T05' => ['from' => [self::InProgress, self::Assigned], 'to' => self::Completed],
            'T06' => ['from' => [self::Assigned, self::InProgress, self::Waiting], 'to' => null],
            'T07' => ['from' => [self::Unassigned, self::Assigned, self::InProgress, self::Waiting], 'to' => self::Cancelled],
            'T08' => ['from' => [self::Completed], 'to' => self::Assigned],
            'T09' => ['from' => [self::Assigned, self::InProgress, self::Waiting], 'to' => self::Unassigned],
            'T10' => ['from' => [self::Unassigned], 'to' => self::Assigned],
        ];
    }

    public function isOpen(): bool
    {
        return $this !== self::Completed && $this !== self::Cancelled;
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Unassigned, self::Assigned, self::InProgress, self::Waiting];
    }
}
