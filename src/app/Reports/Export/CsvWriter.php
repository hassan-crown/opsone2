<?php

namespace App\Reports\Export;

/**
 * CSV a spreadsheet opens safely (SHR-12, NFR-16): UTF-8 with BOM so Arabic opens right, CRLF, every text cell
 * quoted, and any text cell a spreadsheet could read as a formula neutralized (OWASP CSV injection): a leading
 * `=`, `+`, `-`, `@`, tab, CR or LF gets a single quote in front. Typed numbers and money are written as numbers —
 * they never come from user text.
 */
final class CsvWriter
{
    public static function neutralize(string $text): string
    {
        return preg_match('/^ *[=+\-@]|^[\t\r\n]/', $text) === 1 ? "'".$text : $text;
    }

    public static function text(?string $value): string
    {
        return '"'.str_replace('"', '""', self::neutralize((string) $value)).'"';
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<array{type: string, value: scalar|null}>>  $rows
     */
    public static function render(array $header, array $rows): string
    {
        $out = "\xEF\xBB\xBF".implode(',', array_map(fn (string $h) => self::text($h), $header))."\r\n";
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = match (true) {
                    $cell['value'] === null => '',
                    $cell['type'] === 'number' => (string) $cell['value'],
                    default => self::text((string) $cell['value']),
                };
            }
            $out .= implode(',', $cells)."\r\n";
        }

        return $out;
    }
}
