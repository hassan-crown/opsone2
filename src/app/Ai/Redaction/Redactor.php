<?php

namespace App\Ai\Redaction;

/**
 * Hides identifiers before anything leaves the deployment and puts them back in the answer (plan §3.5, ADR-0050 §5).
 *
 * One instance per call: the same value always gets the same token ([PHONE_1], [EMAIL_1], [NATIONAL_ID_1],
 * [IBAN_1], or a caller kind such as [ADDRESS_1]), so the model can still refer to it. Found by pattern: e-mail,
 * IBAN, Saudi national/iqama number, phone numbers. Values no pattern can find (a detailed address) are passed in
 * as `known`. First names are not hidden (greeting).
 *
 * Eastern Arabic digits are matched too («٠٥٥…»), and the original form is what comes back on restore. The same value
 * in either digit form gets one token (the first form seen is restored). Text that already looks like one of our
 * tokens is defused, so a customer cannot plant «[PHONE_1]» to have someone else's value restored.
 */
final class Redactor
{
    private const TOKEN = '/\[([A-Z][A-Z_]*)_(\d+)\]/';

    /** A digit in ASCII, Arabic-Indic or Extended Arabic-Indic (Persian) form (regex class, /u). */
    private const DIGIT = '[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]';

    /** @var list<string> Eastern forms of 0–9, for known-value patterns. */
    private const EASTERN = ['٠۰', '١۱', '٢۲', '٣۳', '٤۴', '٥۵', '٦۶', '٧۷', '٨۸', '٩۹'];

    /** @var array<string, string> token => original */
    private array $tokens = [];

    /** @var array<string, string> kind|value => token */
    private array $byValue = [];

    /** @var array<string, int> */
    private array $counters = [];

    /**
     * @param  array<string, list<string>>  $known  kind => values (kind in upper snake case, e.g. ADDRESS)
     */
    public function __construct(private readonly array $known = []) {}

    public function redact(string $text): string
    {
        // Defuse look-alike tokens coming from the data itself.
        $text = (string) preg_replace(self::TOKEN, '($1-$2)', $text);

        foreach ($this->known as $kind => $values) {
            $kind = strtoupper((string) preg_replace('/[^A-Za-z_]/', '', (string) $kind)) ?: 'VALUE';
            // Longest first, so a value containing another is hidden whole.
            usort($values, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
            foreach ($values as $value) {
                $value = trim(self::foldDigits($value));
                // Too short, or a short run of digits and separators («50», «1,200»): hiding it would hide figures
                // (a long number such as an account number is still hidden).
                if (mb_strlen($value) < 3 || (mb_strlen($value) < 7 && preg_match('/^[\d\s.,٫٬+\-%]+$/u', $value) === 1)) {
                    continue;
                }
                // Each ASCII digit of the value also matches its Eastern Arabic forms.
                $pattern = preg_replace_callback('/\d/', fn (array $d) => '['.$d[0].self::EASTERN[(int) $d[0]].']', preg_quote($value, '/'));
                $text = (string) preg_replace_callback('/'.$pattern.'/iu', fn (array $m) => $this->token($kind, $m[0]), $text);
            }
        }

        $d = self::DIGIT;

        $text = (string) preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', fn (array $m) => $this->token('EMAIL', $m[0]), $text);

        // IBAN: SA + 22 (spaces allowed), or any compact IBAN.
        $text = (string) preg_replace_callback("/(?<![A-Za-z0-9])(?:SA{$d}{2}(?:[ \\-]?(?:{$d}|[A-Z])){20}|[A-Z]{2}{$d}{2}(?:{$d}|[A-Z]){11,30})(?![A-Za-z0-9])/iu", fn (array $m) => $this->token('IBAN', $m[0]), $text);

        // Saudi national ID (1…) or iqama (2…): exactly ten digits.
        $text = (string) preg_replace_callback("/(?<!{$d}){$d}{10}(?!{$d})/u", function (array $m): string {
            $digits = self::foldDigits($m[0]);

            return $digits[0] === '1' || $digits[0] === '2' ? $this->token('NATIONAL_ID', $m[0]) : $m[0];
        }, $text);

        // Phone numbers: +/00 international, bare 966 (E.164 without +, as WhatsApp stores it), 0-prefixed local, or a
        // bare Saudi mobile (5 + 8 digits); spaces, dashes and parentheses allowed.
        return (string) preg_replace_callback("/(?<![0-9A-Za-z\\x{0660}-\\x{0669}\\x{06F0}-\\x{06F9}])(?:\\+|00|٠٠)?\\(?{$d}(?:{$d}|[ \\-()]){7,40}{$d}\\)?(?![0-9A-Za-z\\x{0660}-\\x{0669}\\x{06F0}-\\x{06F9}])/u", fn (array $m) => $this->phonesIn($m[0]), $text);
    }

    /**
     * A run of digits and separators may hold a phone next to other numbers («2026-11-20 0551234567»): try the longest
     * space-separated stretches first and hide each one that is a phone.
     */
    private function phonesIn(string $run): string
    {
        $parts = preg_split('/(\s+)/u', $run, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$run];
        $words = array_values(array_filter(array_keys($parts), fn (int $i) => $i % 2 === 0));
        $out = '';
        $i = 0;
        while ($i < count($words)) {
            $found = null;
            for ($j = count($words) - 1; $j >= $i; $j--) {
                $candidate = implode('', array_slice($parts, $words[$i], $words[$j] - $words[$i] + 1));
                if (self::isPhone($candidate)) {
                    $found = $j;
                    $out .= $this->token('PHONE', $candidate);
                    break;
                }
            }
            $last = $found ?? $i;
            if ($found === null) {
                $out .= $parts[$words[$i]];
            }
            if (isset($parts[$words[$last] + 1])) {
                $out .= $parts[$words[$last] + 1];
            }
            $i = $last + 1;
        }

        return $out;
    }

    private static function isPhone(string $candidate): bool
    {
        $raw = self::foldDigits(trim($candidate));
        if (preg_match('/^(?:\+|00)?\(?[0-9]/', $raw) !== 1 || preg_match('/[0-9]\)?$/', $raw) !== 1) {
            return false;
        }
        $digits = (string) preg_replace('/\D/', '', $raw);
        $length = strlen($digits);

        return ((str_starts_with($raw, '+') || str_starts_with($raw, '00')) && $length >= 9 && $length <= 15)
            || (str_starts_with($digits, '966') && $length >= 11 && $length <= 12)
            || (str_starts_with($digits, '0') && ! str_starts_with($digits, '00') && $length >= 9 && $length <= 11)
            || ($length === 9 && str_starts_with($digits, '5'));
    }

    /**
     * Redacts every string inside an array (keys untouched); other scalars pass through.
     */
    public function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redact($value);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $v) => $this->redactValue($v), $value);
        }

        return $value;
    }

    /**
     * Puts the hidden values back into a model answer (strings anywhere in an array). Unknown tokens stay as they are.
     */
    public function restore(mixed $value): mixed
    {
        if (is_string($value)) {
            return (string) preg_replace_callback(self::TOKEN, fn (array $m) => $this->tokens[$m[0]] ?? $m[0], $value);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $v) => $this->restore($v), $value);
        }

        return $value;
    }

    /**
     * @return array<string, string> token => original
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public static function foldDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    private function token(string $kind, string $original): string
    {
        $key = $kind.'|'.mb_strtolower((string) preg_replace('/[\s\-()]/', '', self::foldDigits(trim($original))));
        if (isset($this->byValue[$key])) {
            return $this->byValue[$key];
        }

        $this->counters[$kind] = ($this->counters[$kind] ?? 0) + 1;
        $token = '['.$kind.'_'.$this->counters[$kind].']';
        $this->byValue[$key] = $token;
        $this->tokens[$token] = trim($original);

        return $token;
    }
}
