<?php

namespace App\Files\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Upload check for private attachments (NFR-14): the extension must be on the allowlist, and the content must
 * agree with it twice — the detected MIME type (finfo) and the leading magic bytes. A renamed executable or
 * script fails even when its extension looks harmless. Use in Form Requests; AttachmentService re-runs it.
 */
final class AllowedAttachment implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(__('files.error.unreadable'));

            return;
        }

        $size = (int) $value->getSize();
        if ($size <= 0) {
            $fail(__('files.error.empty'));

            return;
        }
        if ($size > self::maxBytes()) {
            $fail(__('files.error.too_large', ['max' => (int) round(self::maxBytes() / 1048576)]));

            return;
        }

        if (self::detect($value) === null) {
            $fail(__('files.error.type', ['types' => implode(', ', array_keys(self::types()))]));
        }
    }

    /**
     * The allowlisted extension the file really is, or null.
     */
    public static function detect(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $type = self::types()[$extension] ?? null;
        if ($type === null) {
            return null;
        }

        $mime = $file->getMimeType();
        if ($mime === null || ! in_array($mime, $type['mimes'], true)) {
            return null;
        }

        $head = self::head($file);
        $matches = false;
        foreach ($type['magic'] as $magic) {
            if (str_starts_with($head, $magic)) {
                $matches = true;
                break;
            }
        }
        if ($extension === 'webp') {
            $matches = $matches && substr($head, 8, 4) === 'WEBP';
        }

        return $matches ? $extension : null;
    }

    public static function maxBytes(): int
    {
        return (int) config('attachments.max_kb', 10240) * 1024;
    }

    /**
     * @return array<string, array{mimes: list<string>, magic: list<string>}>
     */
    public static function types(): array
    {
        /** @var array<string, array{mimes: list<string>, magic: list<string>}> $types */
        $types = (array) config('attachments.types', []);

        return $types;
    }

    private static function head(UploadedFile $file): string
    {
        $handle = @fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return '';
        }
        $head = (string) fread($handle, 16);
        fclose($handle);

        return $head;
    }
}
