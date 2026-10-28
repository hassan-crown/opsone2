<?php

namespace App\Support\Translatable;

use JsonSerializable;
use Stringable;

/**
 * A bilingual text value `{ar, en}` (Q-28). `get()` falls back to the other locale when the asked one is empty.
 */
final readonly class Translated implements JsonSerializable, Stringable
{
    public const LOCALES = ['ar', 'en'];

    public function __construct(public ?string $ar = null, public ?string $en = null) {}

    /**
     * @param  array<string, mixed>|null  $value
     */
    public static function fromArray(?array $value): self
    {
        return new self(self::clean($value['ar'] ?? null), self::clean($value['en'] ?? null));
    }

    public function get(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $other = $locale === 'ar' ? 'en' : 'ar';

        return $this->only($locale) ?? $this->only($other);
    }

    /**
     * The text of exactly one locale, with no fallback.
     */
    public function only(string $locale): ?string
    {
        return $locale === 'ar' ? $this->ar : $this->en;
    }

    public function isEmpty(): bool
    {
        return $this->ar === null && $this->en === null;
    }

    /**
     * @return array{ar: string|null, en: string|null}
     */
    public function toArray(): array
    {
        return ['ar' => $this->ar, 'en' => $this->en];
    }

    /**
     * @return array{ar: string|null, en: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return (string) $this->get();
    }

    private static function clean(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }
        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
