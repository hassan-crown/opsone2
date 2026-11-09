<?php

namespace App\Whatsapp\Inbox;

use finfo;

/**
 * The checks every driver applies to received media: the declared type is on the allow-list, the declared and the
 * actual size are within the limit, and the bytes themselves are of the declared type (a renamed file is refused).
 */
final class MediaCheck
{
    /**
     * @param  array<string, string>  $allowedTypes
     */
    public static function declared(string $mime, ?int $size, array $allowedTypes, int $maxBytes): void
    {
        if (! array_key_exists(self::base($mime), $allowedTypes)) {
            throw new MediaRejected('type');
        }
        if ($size !== null && $size > $maxBytes) {
            throw new MediaRejected('size');
        }
    }

    /**
     * @param  array<string, string>  $allowedTypes
     */
    public static function content(string $bytes, string $declared, array $allowedTypes, int $maxBytes): MediaFile
    {
        if (strlen($bytes) > $maxBytes) {
            throw new MediaRejected('size');
        }
        if ($bytes === '') {
            throw new MediaRejected('type');
        }
        $detected = self::base((string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        $declared = self::base($declared);
        if (! array_key_exists($detected, $allowedTypes) || ! self::sameFamily($detected, $declared)) {
            throw new MediaRejected('type');
        }

        return new MediaFile($bytes, $detected);
    }

    public static function base(string $mime): string
    {
        return strtolower(trim(explode(';', $mime)[0]));
    }

    /** WhatsApp voice notes are declared `audio/ogg; codecs=opus`; libmagic may name an AAC/MP4 variant differently. */
    private static function sameFamily(string $detected, string $declared): bool
    {
        if ($detected === $declared) {
            return true;
        }

        return str_starts_with($detected, 'audio/') && str_starts_with($declared, 'audio/');
    }
}
