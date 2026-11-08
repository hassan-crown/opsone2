<?php

namespace App\Help\KnowledgeBase;

/**
 * The deliberately small YAML subset our help files use (ADR-0048): one `key: value` per line between `---` fences,
 * where a value is a plain or quoted string, an integer, or a flow list `[a, b]`. `#` starts a comment outside
 * quotes. Anything else is an error, so a typo never turns into a silently different article.
 * (symfony/yaml is a dev-only dependency here, and help:sync must run in production.)
 */
final class FrontMatter
{
    /**
     * @return array{meta: array<string, string|int|list<string>|null>, body: string}
     *
     * @throws InvalidArticleFile
     */
    public static function parse(string $contents): array
    {
        $contents = str_replace("\r\n", "\n", $contents);
        if (str_starts_with($contents, "\u{FEFF}")) {
            $contents = substr($contents, 3);
        }

        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $contents, $m)) {
            throw new InvalidArticleFile('missing the frontmatter block between two «---» lines');
        }

        $meta = [];
        foreach (explode("\n", $m[1]) as $number => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (! preg_match('/^([a-z_]+)\s*:\s*(.*)$/', $trimmed, $pair)) {
                throw new InvalidArticleFile('frontmatter line '.($number + 2).' is not «key: value»');
            }

            [$key, $raw] = [$pair[1], $pair[2]];
            if (array_key_exists($key, $meta)) {
                throw new InvalidArticleFile("duplicate frontmatter key «{$key}»");
            }

            $meta[$key] = self::value($raw, $key);
        }

        return ['meta' => $meta, 'body' => trim($m[2])];
    }

    /**
     * @return string|int|list<string>|null
     *
     * @throws InvalidArticleFile
     */
    private static function value(string $raw, string $key): string|int|array|null
    {
        $raw = trim($raw);

        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            $quote = $raw[0];
            $end = strpos($raw, $quote, 1);
            if ($end === false) {
                throw new InvalidArticleFile("unterminated quote in «{$key}»");
            }
            $rest = trim(substr($raw, $end + 1));
            if ($rest !== '' && ! str_starts_with($rest, '#')) {
                throw new InvalidArticleFile("unexpected text after the quoted value of «{$key}»");
            }

            return substr($raw, 1, $end - 1);
        }

        $raw = trim((string) preg_replace('/\s+#.*$/', '', $raw));

        if ($raw === '' || $raw === '~' || $raw === 'null') {
            return null;
        }

        if (str_starts_with($raw, '[')) {
            if (! str_ends_with($raw, ']')) {
                throw new InvalidArticleFile("unterminated list in «{$key}»");
            }
            $inner = trim(substr($raw, 1, -1));
            if ($inner === '') {
                return [];
            }

            return array_map(
                fn (string $item) => trim(trim($item), '"\''),
                explode(',', $inner),
            );
        }

        if (preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }

        return $raw;
    }
}
