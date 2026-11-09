<?php

namespace App\Ai\Validation;

use App\Ai\Redaction\Redactor;

/**
 * The general check every model answer passes before a feature sees it (plan §3.1, §3.3).
 *
 * 1. Schema: a strict subset of JSON Schema (type, properties, required, additionalProperties, enum, items,
 *    minItems/maxItems, minLength/maxLength, minimum/maximum). The API enforces the schema too; this is the
 *    second, local line.
 * 2. Facts: every number (two digits or more), date and link inside an answer string, and every numeric value of 10
 *    or more, must come from the trusted context. Amounts given as `*_halalas` also count in riyals. A link must also be on one of the facility's
 *    own hosts. Placeholders such as [PHONE_1] are ignored. One-digit numbers are free («خطوتان», «3 أيام»).
 *
 * Strict mode (opt-in per call, `AiCall::strictFacts`; used where the answer states figures — digest, «اسأل بياناتك»):
 * the date parts of the context (1–31, months, years) do not make numbers «sourced» — a date in the answer must be a
 * whole `YYYY-MM-DD` date of the context; one-digit numbers are checked too; a percentage (`%`, «نسبة» + number) must
 * equal a context value whose key says percent/ratio/share; and multiples («ضعف», «أضعاف», «مرتين») are refused,
 * since no tool provides them. Default mode is unchanged.
 *
 * Returns the problems found (empty = valid). Messages name paths and kinds, never the offending text.
 */
final class OutputValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $allowedHosts
     * @param  array<string, true>  $ratios  strict mode: the percent/ratio values of the context (see ratios())
     * @return list<string>
     */
    public function validate(mixed $output, array $schema, string $sourceCorpus, array $allowedHosts, bool $strict = false, array $ratios = []): array
    {
        $problems = [];
        $this->checkSchema($output, $schema, '$', $problems);

        if ($problems !== []) {
            return $problems;
        }

        $sources = self::numbersIn($strict ? self::withoutDates($sourceCorpus) : $sourceCorpus);
        $context = $strict ? ['dates' => self::datesIn($sourceCorpus), 'ratios' => $ratios] : null;
        $this->checkFacts($output, '$', $sources, $sourceCorpus, array_map('strtolower', $allowedHosts), $problems, $context);

        return $problems;
    }

    /**
     * The trusted context as one searchable text, with halala amounts also as riyals.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function corpus(array $facts, string ...$texts): string
    {
        $extra = [];
        array_walk_recursive($facts, function (mixed $value, int|string $key) use (&$extra): void {
            if (is_string($key) && str_ends_with($key, '_halalas') && is_numeric($value)) {
                $riyals = ((int) $value) / 100;
                $extra[] = number_format($riyals, 2, '.', '');
                if (fmod($riyals, 1.0) === 0.0) {
                    $extra[] = (string) (int) $riyals;
                }
            }
        });

        return implode("\n", [(string) json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ...$texts, ...$extra]);
    }

    /**
     * Numbers of the context stored under a key that says percent, ratio or share (strict mode's percentage sources).
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, true>
     */
    public static function ratios(array $facts): array
    {
        $out = [];
        $walk = function (mixed $value, int|string|null $key) use (&$walk, &$out): void {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $walk($v, is_string($k) ? $k : $key);
                }

                return;
            }
            if (is_numeric($value) && is_string($key) && preg_match('/percent|ratio|share/i', $key) === 1) {
                $out += self::numbersIn((string) abs((float) $value));
            }
        };
        $walk($facts, null);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $problems
     */
    private function checkSchema(mixed $value, array $schema, string $path, array &$problems): void
    {
        $types = array_values((array) ($schema['type'] ?? []));
        if ($types !== [] && ! $this->matchesType($value, $types)) {
            $problems[] = "{$path}: expected ".implode('|', $types);

            return;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $problems[] = "{$path}: not an allowed value";
        }

        if (is_string($value)) {
            if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                $problems[] = "{$path}: too long";
            }
            if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
                $problems[] = "{$path}: too short";
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $problems[] = "{$path}: below minimum";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $problems[] = "{$path}: above maximum";
            }
        }

        if (is_array($value) && array_is_list($value) && in_array('array', $types, true)) {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                $problems[] = "{$path}: too few items";
            }
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                $problems[] = "{$path}: too many items";
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    $this->checkSchema($item, $schema['items'], "{$path}[{$i}]", $problems);
                }
            }

            return;
        }

        if (is_array($value) && in_array('object', $types, true)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists((string) $required, $value)) {
                    $problems[] = "{$path}.{$required}: missing";
                }
            }
            foreach ($value as $key => $item) {
                if (isset($properties[$key]) && is_array($properties[$key])) {
                    $this->checkSchema($item, $properties[$key], "{$path}.{$key}", $problems);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $problems[] = "{$path}.{$key}: not allowed";
                }
            }
        }
    }

    /**
     * @param  list<mixed>  $types
     */
    private function matchesType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, true>  $sources
     * @param  list<string>  $hosts
     * @param  list<string>  $problems
     * @param  array{dates: array<string, true>, ratios: array<string, true>}|null  $strict
     */
    private function checkFacts(mixed $value, string $path, array $sources, string $corpus, array $hosts, array &$problems, ?array $strict = null): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->checkFacts($item, is_int($key) ? "{$path}[{$key}]" : "{$path}.{$key}", $sources, $corpus, $hosts, $problems, $strict);
            }

            return;
        }

        if (is_int($value) || is_float($value)) {
            // A numeric field («amount»: 1500) needs a source like a number in text. Small values (scores, counts
            // below 10, confidences) are free.
            if (abs($value) >= 10 && ! isset($sources[array_key_first(self::numbersIn((string) abs($value)))])) {
                $problems[] = "{$path}: number without a source";
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        $text = Redactor::foldDigits($value);

        preg_match_all('~\bhttps?://[^\s<>"\')\]]+~iu', $text, $links);
        foreach ($links[0] as $link) {
            $host = strtolower((string) parse_url($link, PHP_URL_HOST));
            if (! in_array($host, $hosts, true)) {
                $problems[] = "{$path}: link outside the facility";
            } elseif (! str_contains($corpus, rtrim($link, '.,،'))) {
                $problems[] = "{$path}: link without a source";
            }
            $text = str_replace($link, ' ', $text);
        }

        $text = (string) preg_replace('/\[[A-Z][A-Z_]*_\d+\]/', ' ', $text);

        if ($strict !== null) {
            foreach (array_keys(self::datesIn($text)) as $date) {
                if (! isset($strict['dates'][$date])) {
                    $problems[] = "{$path}: date without a source";

                    return;
                }
            }
            $text = self::withoutDates($text);

            if (preg_match('/ضعف|أضعاف|اضعاف|مرتين/u', $text) === 1) {
                $problems[] = "{$path}: multiple without a source";

                return;
            }
            preg_match_all('/(\d+(?:\.\d+)?)\s*(?:%|٪|بالمئة|بالمائة|في المئة|في المائة)|نسبة[^\d]{0,25}(\d+(?:\.\d+)?)/u', $text, $ratio);
            foreach (array_filter([...$ratio[1], ...$ratio[2]], fn ($r) => $r !== '') as $r) {
                if (! isset($strict['ratios'][array_key_first(self::numbersIn((string) $r))])) {
                    $problems[] = "{$path}: percentage without a source";

                    return;
                }
            }
        }

        $minDigits = $strict === null ? 2 : 1;
        foreach (array_keys(self::numbersIn($text)) as $number) {
            if (strlen((string) preg_replace('/\D/', '', $number)) >= $minDigits && ! isset($sources[$number])) {
                $problems[] = "{$path}: number or date without a source";

                return;
            }
        }
    }

    /**
     * Whole ISO dates of a text (digits folded), as a set.
     *
     * @return array<string, true>
     */
    private static function datesIn(string $text): array
    {
        preg_match_all('/(?<!\d)\d{4}-\d{2}-\d{2}(?!\d)/', Redactor::foldDigits($text), $m);

        return array_fill_keys($m[0], true);
    }

    private static function withoutDates(string $text): string
    {
        return (string) preg_replace('/(?<!\d)\d{4}-\d{2}-\d{2}(?!\d)/', ' ', Redactor::foldDigits($text));
    }

    /**
     * Numbers in a text, normalized: «1,500.00» → 1500, «12.5» → 12.5. Dates split into their parts.
     *
     * @return array<string, true>
     */
    private static function numbersIn(string $text): array
    {
        $text = Redactor::foldDigits($text);
        preg_match_all('/\d{1,3}(?:[,٬]\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?/u', $text, $m);

        $numbers = [];
        foreach ($m[0] as $raw) {
            $plain = str_replace([',', '٬'], '', $raw);
            if (str_contains($plain, '.')) {
                $plain = rtrim(rtrim($plain, '0'), '.');
            }
            $plain = ltrim($plain, '0') === '' ? '0' : ltrim($plain, '0');
            $numbers[$plain] = true;
        }

        return $numbers;
    }
}
