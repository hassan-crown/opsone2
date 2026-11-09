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
 * Eastern Arabic digits are folded to ASCII first so «٠٥٥…» is found too. Text that already looks like one of our
 * tokens is defused, so a customer cannot plant «[PHONE_1]» to have someone else's value restored.
 */
final class Redactor
{
    private const TOKEN = '/\[([A-Z][A-Z_]*)_(\d+)\]/';

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
        $text = self::foldDigits($text);
        // Defuse look-alike tokens coming from the data itself.
        $text = (string) preg_replace(self::TOKEN, '($1-$2)', $text);

        foreach ($this->known as $kind => $values) {
            $kind = strtoupper((string) preg_replace('/[^A-Za-z_]/', '', (string) $kind)) ?: 'VALUE';
            // Longest first, so a value containing another is hidden whole.
            usort($values, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
            foreach ($values as $value) {
                $value = trim(self::foldDigits($value));
                if (mb_strlen($value) < 3) {
                    continue;
                }
                $text = (string) preg_replace_callback('/'.preg_quote($value, '/').'/iu', fn (array $m) => $this->token($kind, $m[0]), $text);
            }
        }

        $text = (string) preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', fn (array $m) => $this->token('EMAIL', $m[0]), $text);

        // IBAN: SA + 22 (spaces allowed), or any compact IBAN.
        $text = (string) preg_replace_callback('/\b(?:SA\d{2}(?:[ \-]?[0-9A-Z]){20}|[A-Z]{2}\d{2}[0-9A-Z]{11,30})\b/i', fn (array $m) => $this->token('IBAN', $m[0]), $text);

        // Saudi national ID (1…) or iqama (2…): exactly ten digits.
        $text = (string) preg_replace_callback('/(?<![0-9])[12][0-9]{9}(?![0-9])/', fn (array $m) => $this->token('NATIONAL_ID', $m[0]), $text);

        // Phone numbers: +/00 international, 0-prefixed local, or a bare Saudi mobile (5 + 8 digits).
        return (string) preg_replace_callback('/(?<![0-9A-Za-z])(?:\+|00)?[0-9][0-9 \-]{7,17}[0-9](?![0-9A-Za-z])/', function (array $m): string {
            $digits = (string) preg_replace('/\D/', '', $m[0]);
            $length = strlen($digits);
            $international = str_starts_with($m[0], '+') || str_starts_with($m[0], '00');
            $local = str_starts_with($digits, '0') && $length >= 9 && $length <= 11;
            $mobile = $length === 9 && str_starts_with($digits, '5');

            return ($international && $length >= 9 && $length <= 15) || $local || $mobile ? $this->token('PHONE', $m[0]) : $m[0];
        }, $text);
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
        $key = $kind.'|'.mb_strtolower(trim($original));
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
