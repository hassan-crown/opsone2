<?php

namespace App\Enums;

/**
 * M-23 «إعداد مسار الجهة». «غير موجود» is a technical creation point during provisioning, not a state.
 */
enum FlowStatus: string
{
    case Published = 'published';
    case PublishedWithDraft = 'published_with_draft';

    /**
     * @return array<string, array{from: list<self|null>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [null, self::Published], 'to' => self::Published],
            'T02' => ['from' => [self::Published, self::PublishedWithDraft], 'to' => self::PublishedWithDraft],
            'T03' => ['from' => [self::PublishedWithDraft], 'to' => self::Published],
            'T04' => ['from' => [self::PublishedWithDraft], 'to' => self::Published],
        ];
    }
}
