<?php

namespace App\Site;

use App\Files\Rules\AllowedAttachment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Site images (Q-50 a): PNG or WebP only — never SVG or JPEG-as-anything-else — verified by content, with sane
 * dimensions; a favicon must be square.
 */
final class SiteImage implements ValidationRule
{
    public const MAX_KB = 5120;

    public function __construct(private readonly bool $square = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(__('site.error.image'));

            return;
        }
        if ((int) $value->getSize() > self::MAX_KB * 1024) {
            $fail(__('files.error.too_large', ['max' => (int) (self::MAX_KB / 1024)]));

            return;
        }
        $type = AllowedAttachment::detect($value);
        if (! in_array($type, ['png', 'webp'], true)) {
            $fail(__('site.error.image_type'));

            return;
        }
        $info = @getimagesize((string) $value->getRealPath());
        if ($info === false) {
            $fail(__('site.error.image'));

            return;
        }
        [$w, $h] = $info;
        if ($w < 16 || $h < 16 || $w > 4000 || $h > 4000) {
            $fail(__('site.error.image_size'));

            return;
        }
        if ($this->square && $w !== $h) {
            $fail(__('site.error.favicon_square'));
        }
    }
}
