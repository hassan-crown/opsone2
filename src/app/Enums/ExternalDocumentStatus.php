<?php

namespace App\Enums;

/**
 * M-40 «مستند محاسبة خارجي» (FIN-10, Q-39): requested → attached awaiting review → accepted, or needs correction and
 * attached again. Accepted means linked and reviewed internally, never an automatic proof of compliance (BR-20: the
 * tax invoice is issued by the external accounting system).
 */
enum ExternalDocumentStatus: string
{
    case Requested = 'requested';
    case Attached = 'attached';
    case Accepted = 'accepted';
    case NeedsCorrection = 'needs_correction';

    /**
     * @return array<string, array{from: list<self|null>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            // Attaching a file to a request, linking a document nobody requested, or re-attaching after a correction.
            'T01' => ['from' => [self::Requested, null], 'to' => self::Attached],
            'T02' => ['from' => [self::Attached], 'to' => self::Accepted],
            'T03' => ['from' => [self::Attached], 'to' => self::NeedsCorrection],
            'T04' => ['from' => [self::NeedsCorrection], 'to' => self::Attached],
        ];
    }
}
