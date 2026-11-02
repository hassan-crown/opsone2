<?php

namespace App\Procurement;

use App\Models\Procurement\PurchaseDocumentVersion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The facility's purchase-order document (ADR-0038): which blocks appear and in what order, chosen from data the
 * system already holds, plus a few closed options (what the workshop may see of the customer, which line details,
 * the terms text, signature and stamp boxes) and the email's subject and body. No free HTML and no new fields: the
 * owner arranges, the code renders. Every save is a new immutable version; each generated PDF records its version.
 */
final class PurchaseDocumentLayout
{
    /** Blocks in their default order. */
    public const BLOCKS = [
        'facility', 'branch', 'supplier', 'order_reference', 'expected_on', 'delivery_address', 'lines', 'notes', 'terms', 'signatures',
    ];

    /** Boolean options with their defaults: the customer's identity stays hidden from the workshop by default. */
    public const FLAGS = [
        'facility_contact' => true,
        'customer_name' => false,
        'customer_phone' => false,
        'line_dimensions' => true,
        'line_options' => true,
        'line_notes' => true,
        'line_images' => true,
        'prices' => false,
        'signature' => true,
        'stamp' => true,
    ];

    /** Closed placeholders of the email texts. */
    public const PLACEHOLDERS = ['number', 'supplier', 'facility', 'branch', 'expected_on'];

    public const MAX_TERMS = 2000;

    /**
     * @return array{blocks: list<array{key: string, visible: bool}>, flags: array<string, bool>, title: array{ar: string, en: string|null}, terms: array{ar: string|null, en: string|null}}
     */
    public static function defaultLayout(): array
    {
        return [
            'blocks' => array_map(fn (string $k) => ['key' => $k, 'visible' => true], self::BLOCKS),
            'flags' => self::FLAGS,
            'title' => ['ar' => 'أمر شراء', 'en' => 'Purchase order'],
            'terms' => [
                'ar' => 'يُرجى الالتزام بالمقاسات والمواصفات المرفقة وتاريخ التسليم المتوقع، وإبلاغنا فوراً بأي تأخير أو ملاحظة.',
                'en' => 'Please follow the attached measurements and specifications and the expected date, and tell us at once of any delay or remark.',
            ],
        ];
    }

    /**
     * @return array{subject: array{ar: string, en: string}, body: array{ar: string, en: string}}
     */
    public static function defaultEmail(): array
    {
        return [
            'subject' => ['ar' => 'أمر شراء {number} — {facility}', 'en' => 'Purchase order {number} — {facility}'],
            'body' => [
                'ar' => "السادة {supplier}،\nتجدون مرفقاً أمر الشراء {number} من {facility} ({branch}).\nالتاريخ المتوقع للتسليم: {expected_on}.\nشاكرين تعاونكم.",
                'en' => "Dear {supplier},\nPlease find attached purchase order {number} from {facility} ({branch}).\nExpected date: {expected_on}.\nThank you.",
            ],
        ];
    }

    /**
     * The current version, or the built-in defaults as version 0 before the owner saves anything.
     *
     * @return array{version: int, layout: array<string, mixed>, email: array<string, mixed>}
     */
    public static function current(): array
    {
        $row = PurchaseDocumentVersion::query()->orderByDesc('version')->first();

        return $row === null
            ? ['version' => 0, 'layout' => self::defaultLayout(), 'email' => self::defaultEmail()]
            : ['version' => $row->version, 'layout' => self::normalize($row->layout), 'email' => $row->email];
    }

    /**
     * Validates and normalizes a submitted layout: every known block exactly once (unknown ones dropped, missing ones
     * appended hidden), known flags only, bounded texts.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $email
     * @return array{layout: array<string, mixed>, email: array{subject: array{ar: string, en: string}, body: array{ar: string, en: string}}}
     */
    public static function validated(array $layout, array $email): array
    {
        $v = Validator::make(['layout' => $layout, 'email' => $email], [
            'layout.blocks' => ['required', 'array', 'max:'.count(self::BLOCKS)],
            'layout.blocks.*.key' => ['required', 'string', 'in:'.implode(',', self::BLOCKS), 'distinct'],
            'layout.blocks.*.visible' => ['required', 'boolean'],
            'layout.flags' => ['required', 'array'],
            'layout.flags.*' => ['boolean'],
            'layout.title.ar' => ['required', 'string', 'max:60'],
            'layout.title.en' => ['nullable', 'string', 'max:60'],
            'layout.terms.ar' => ['nullable', 'string', 'max:'.self::MAX_TERMS],
            'layout.terms.en' => ['nullable', 'string', 'max:'.self::MAX_TERMS],
            'email.subject.ar' => ['required', 'string', 'max:150'],
            'email.subject.en' => ['nullable', 'string', 'max:150'],
            'email.body.ar' => ['required', 'string', 'max:3000'],
            'email.body.en' => ['nullable', 'string', 'max:3000'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        foreach (['subject', 'body'] as $field) {
            foreach (['ar', 'en'] as $locale) {
                $text = (string) ($email[$field][$locale] ?? '');
                preg_match_all('/\{([a-z_]+)\}/', $text, $m);
                if (array_diff($m[1], self::PLACEHOLDERS) !== []) {
                    throw ValidationException::withMessages(["email.{$field}.{$locale}" => __('purchase_document.error.placeholder', ['allowed' => '{'.implode('} {', self::PLACEHOLDERS).'}'])]);
                }
            }
        }

        return [
            'layout' => self::normalize($layout),
            'email' => [
                'subject' => ['ar' => trim((string) $email['subject']['ar']), 'en' => self::text($email['subject']['en'] ?? null) ?? ''],
                'body' => ['ar' => trim((string) $email['body']['ar']), 'en' => self::text($email['body']['en'] ?? null) ?? ''],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    public static function normalize(array $layout): array
    {
        $blocks = [];
        foreach ((array) ($layout['blocks'] ?? []) as $block) {
            $key = is_array($block) ? ($block['key'] ?? null) : null;
            if (in_array($key, self::BLOCKS, true) && ! isset($blocks[$key])) {
                $blocks[$key] = ['key' => $key, 'visible' => (bool) ($block['visible'] ?? true)];
            }
        }
        foreach (self::BLOCKS as $key) {
            $blocks[$key] ??= ['key' => $key, 'visible' => false];
        }
        $flags = [];
        foreach (self::FLAGS as $flag => $default) {
            $flags[$flag] = (bool) ($layout['flags'][$flag] ?? $default);
        }
        $defaults = self::defaultLayout();

        return [
            'blocks' => array_values($blocks),
            'flags' => $flags,
            'title' => ['ar' => self::text($layout['title']['ar'] ?? null) ?? $defaults['title']['ar'], 'en' => self::text($layout['title']['en'] ?? null)],
            'terms' => ['ar' => self::text($layout['terms']['ar'] ?? null), 'en' => self::text($layout['terms']['en'] ?? null)],
        ];
    }

    /**
     * Fills the closed placeholders of an email text.
     *
     * @param  array<string, string>  $values
     */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn (array $m) => $values[$m[1]] ?? $m[0], $text);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
