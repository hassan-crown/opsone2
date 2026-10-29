<?php

/*
 * Bilingual completeness (NFR, ar default + en): both language files carry the same keys, no empty text, the same
 * :placeholders per key, and every literal key the PHP code translates exists.
 */

/**
 * @return array<string, string>
 */
function langFile(string $locale): array
{
    return json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
}

it('has identical key sets in ar.json and en.json', function () {
    $ar = array_keys(langFile('ar'));
    $en = array_keys(langFile('en'));

    expect(array_values(array_diff($ar, $en)))->toBe([], 'keys only in ar.json')
        ->and(array_values(array_diff($en, $ar)))->toBe([], 'keys only in en.json');
});

it('has no empty translation and the same placeholders in both languages', function () {
    $ar = langFile('ar');
    $en = langFile('en');
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $m);
        $list = array_unique($m[0]);
        sort($list);

        return $list;
    };

    $empty = [];
    $mismatch = [];
    foreach ($en as $key => $text) {
        if (trim((string) $text) === '' || trim((string) ($ar[$key] ?? '')) === '') {
            $empty[] = $key;
        }
        if (isset($ar[$key]) && $placeholders((string) $text) !== $placeholders((string) $ar[$key])) {
            $mismatch[] = $key;
        }
    }

    expect($empty)->toBe([])->and($mismatch)->toBe([]);
});

it('defines every literal key the PHP code translates', function () {
    $keys = langFile('en');
    $missing = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        preg_match_all('/(?<![\w>:])__\(\s*\'([a-z0-9_]+(?:\.[a-z0-9_\-]+)+)\'/', (string) file_get_contents($file->getPathname()), $m);
        foreach ($m[1] as $key) {
            // Dynamic keys end with the separator the variable part follows (e.g. 'org.invite.error_'.$code).
            if (! str_ends_with($key, '_') && ! isset($keys[$key]) && ! str_starts_with($key, 'validation.')) {
                $missing[$key] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($missing)->toBe([]);
});
