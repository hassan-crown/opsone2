<?php

namespace App\Site\Sections;

use App\Models\Attachment;
use App\Models\Site\SitePage;
use Illuminate\Validation\ValidationException;

/**
 * The fixed design-system section types of the page editor (Q-51 a): no free HTML, no scripts. Every section is
 * `{id, type, visible, data}`; `normalize()` keeps only the known keys of a type and plain text, `incomplete()`
 * lists what is missing before a language may be published.
 */
final class SectionCatalogue
{
    public const TYPES = ['hero', 'text', 'services', 'gallery', 'testimonials', 'faq', 'contact', 'ready_goods'];

    /** type => [scalar text fields => [max length, required to publish]] */
    private const TEXT = [
        'hero' => ['heading' => [160, false], 'subheading' => [300, false], 'cta_label' => [60, false]],
        'text' => ['heading' => [160, false], 'body' => [4000, true]],
        'services' => ['heading' => [160, true]],
        'gallery' => ['heading' => [160, false]],
        'testimonials' => ['heading' => [160, false]],
        'faq' => ['heading' => [160, false]],
        'contact' => ['heading' => [160, true], 'text' => [400, false]],
        'ready_goods' => ['heading' => [160, true], 'text' => [400, false]],
    ];

    /** type => [list field => [max items, [item field => [max length, required]]]] */
    private const LISTS = [
        'services' => ['items' => [8, ['title' => [120, true], 'text' => [400, false]]]],
        'testimonials' => ['items' => [6, ['quote' => [400, true], 'author' => [120, true], 'role' => [120, false]]]],
        'faq' => ['items' => [12, ['question' => [200, true], 'answer' => [1000, true]]]],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function empty(string $type): array
    {
        $data = [];
        foreach (self::TEXT[$type] ?? [] as $field => $_) {
            $data[$field] = '';
        }
        foreach (self::LISTS[$type] ?? [] as $field => $_) {
            $data[$field] = [];
        }
        if ($type === 'hero') {
            $data += ['cta_link' => null, 'image' => null];
        }
        if ($type === 'gallery') {
            $data['images'] = [];
        }
        if ($type === 'contact') {
            $data['show_form'] = true;
        }

        return $data;
    }

    /**
     * @return list<array{id: string, type: string, visible: bool, data: array<string, mixed>}>
     */
    public static function normalize(mixed $sections, string $field = 'sections'): array
    {
        if (! is_array($sections)) {
            return [];
        }
        $out = [];
        $errors = [];
        foreach (array_values($sections) as $i => $raw) {
            $type = is_array($raw) ? ($raw['type'] ?? null) : null;
            if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
                $errors["$field.$i.type"] = __('site.error.section_type');

                continue;
            }
            $in = is_array($raw['data'] ?? null) ? $raw['data'] : [];
            $data = [];
            foreach (self::TEXT[$type] as $key => [$max, $_]) {
                $data[$key] = self::text($in[$key] ?? '', $max, "$field.$i.data.$key", $errors);
            }
            foreach (self::LISTS[$type] ?? [] as $key => [$maxItems, $fields]) {
                $items = [];
                foreach (array_slice(array_values(is_array($in[$key] ?? null) ? $in[$key] : []), 0, $maxItems) as $j => $item) {
                    $row = [];
                    foreach ($fields as $f => [$max, $_]) {
                        $row[$f] = self::text(is_array($item) ? ($item[$f] ?? '') : '', $max, "$field.$i.data.$key.$j.$f", $errors);
                    }
                    $items[] = $row;
                }
                $data[$key] = $items;
            }
            if ($type === 'hero') {
                $data['cta_link'] = self::link($in['cta_link'] ?? null);
                $data['image'] = self::uuid($in['image'] ?? null);
            }
            if ($type === 'gallery') {
                $images = [];
                foreach (array_slice(array_values(is_array($in['images'] ?? null) ? $in['images'] : []), 0, 12) as $j => $img) {
                    $uuid = self::uuid(is_array($img) ? ($img['image'] ?? null) : null);
                    if ($uuid !== null) {
                        $images[] = ['image' => $uuid, 'caption' => self::text($img['caption'] ?? '', 160, "$field.$i.data.images.$j.caption", $errors)];
                    }
                }
                $data['images'] = $images;
            }
            if ($type === 'contact') {
                $data['show_form'] = (bool) ($in['show_form'] ?? true);
            }
            $id = is_string($raw['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $raw['id']) ? $raw['id'] : 's'.bin2hex(random_bytes(4));
            $out[] = ['id' => $id, 'type' => $type, 'visible' => (bool) ($raw['visible'] ?? true), 'data' => $data];
        }
        if (count($out) > 30) {
            $errors[$field] = __('site.error.too_many_sections');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * Issues that block publishing this language: `[['section' => index|null, 'code' => ..., 'field' => ...]]`.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array{section: int|null, code: string, field: string|null}>
     */
    public static function incomplete(array $sections): array
    {
        $issues = [];
        $visible = array_filter($sections, fn ($s) => $s['visible']);
        if ($visible === []) {
            $issues[] = ['section' => null, 'code' => 'no_sections', 'field' => null];
        }
        foreach ($sections as $i => $section) {
            if (! $section['visible']) {
                continue;
            }
            $type = $section['type'];
            $data = $section['data'];
            foreach (self::TEXT[$type] as $field => [$_, $required]) {
                if ($required && trim((string) ($data[$field] ?? '')) === '') {
                    $issues[] = ['section' => $i, 'code' => 'required', 'field' => $field];
                }
            }
            foreach (self::LISTS[$type] ?? [] as $field => [$_, $fields]) {
                $items = $data[$field] ?? [];
                if ($items === []) {
                    $issues[] = ['section' => $i, 'code' => 'items_required', 'field' => $field];
                }
                foreach ($items as $j => $item) {
                    foreach ($fields as $f => [$__, $required]) {
                        if ($required && trim((string) ($item[$f] ?? '')) === '') {
                            $issues[] = ['section' => $i, 'code' => 'required', 'field' => "$field.$j.$f"];
                        }
                    }
                }
            }
            if ($type === 'gallery' && ($data['images'] ?? []) === []) {
                $issues[] = ['section' => $i, 'code' => 'items_required', 'field' => 'images'];
            }
            if ($type === 'hero' && ($data['cta_link'] ?? null) !== null && trim((string) ($data['cta_label'] ?? '')) === '') {
                $issues[] = ['section' => $i, 'code' => 'required', 'field' => 'cta_label'];
            }
            if ($type === 'hero' && ($data['cta_link'] ?? null) !== null && ! self::linkResolves((string) $data['cta_link'])) {
                $issues[] = ['section' => $i, 'code' => 'bad_link', 'field' => 'cta_link'];
            }
        }

        return $issues;
    }

    /**
     * Every attachment uuid a section list references.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<string>
     */
    public static function mediaIn(array $sections): array
    {
        $found = [];
        foreach ($sections as $s) {
            if (($s['data']['image'] ?? null) !== null) {
                $found[] = $s['data']['image'];
            }
            foreach ($s['data']['images'] ?? [] as $img) {
                $found[] = $img['image'];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  list<string>  $uuids
     */
    public static function mediaBelongsTo(array $uuids, SitePage $page): bool
    {
        if ($uuids === []) {
            return true;
        }

        return Attachment::query()->where('attachable_type', $page->getMorphClass())->where('attachable_id', $page->id)
            ->whereIn('uuid', $uuids)->count() === count($uuids);
    }

    public static function linkResolves(string $link): bool
    {
        if ($link === '#contact' || $link === 'home') {
            return true;
        }

        return SitePage::query()->where('slug', $link)->whereNull('archived_at')->exists();
    }

    private static function link(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(#contact|[a-z0-9][a-z0-9-]{0,79})$/', $value) ? $value : null;
    }

    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $errors
     *
     * @param-out array<string, mixed> $errors
     */
    private static function text(mixed $value, int $max, string $key, array &$errors): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if (preg_match('/<\s*\/?\s*[a-z!?]/i', $text) === 1) {
            $errors[$key] = __('site.error.no_html');

            return '';
        }
        if (mb_strlen($text) > $max) {
            $errors[$key] = __('site.error.too_long', ['max' => $max]);

            return mb_substr($text, 0, $max);
        }

        return $text;
    }
}
