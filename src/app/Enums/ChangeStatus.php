<?php

namespace App\Enums;

/**
 * M-09 «تغيير الاتفاق».
 */
enum ChangeStatus: string
{
    case Draft = 'draft';
    case AwaitingApproval = 'awaiting_approval';
    case NeedsRevision = 'needs_revision';
    case Applied = 'applied';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Draft], 'to' => self::AwaitingApproval],
            'T02' => ['from' => [self::AwaitingApproval], 'to' => self::NeedsRevision],
            'T03' => ['from' => [self::NeedsRevision], 'to' => self::AwaitingApproval],
            'T04' => ['from' => [self::AwaitingApproval], 'to' => self::Applied],
            'T05' => ['from' => [self::Draft, self::AwaitingApproval, self::NeedsRevision], 'to' => self::Cancelled],
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::AwaitingApproval, self::NeedsRevision], true);
    }
}
