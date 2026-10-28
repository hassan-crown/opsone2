<?php

namespace App\Agreements;

/**
 * Fingerprint of a document's or change's content: what a customer decision and a discount decision are bound to.
 * Key order does not matter; any value change does.
 */
final class ContentHash
{
    /**
     * @param  array<array-key, mixed>  $content
     */
    public static function of(array $content): string
    {
        return hash('sha256', (string) json_encode(self::canonical($content), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => is_array($v) ? self::canonical($v) : $v, $value);
    }
}
