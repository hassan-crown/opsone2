<?php

namespace App\Numbering;

use App\Configuration\ConfigReleases;
use App\Models\NumberFormat;
use Carbon\CarbonInterface;
use IntlDateFormatter;

/**
 * The closed list of document sequences a facility may format (ADR-0037), with their built-in prefix and digits.
 * Tax invoice sequences (INV/CRN/DBN, ADR-0033) are deliberately absent: ZATCA numbering stays sequential, gapless
 * and fixed. A format only changes how the next number reads; the counter stays one gapless sequence per name and
 * period (and per branch when the format asks for it), so numbers stay unique.
 */
final class NumberFormats
{
    public const YEAR_MODES = ['gregorian', 'hijri', 'none'];

    /** Sequences whose callers know the branch (the branch segment is offered only for these). */
    public const BRANCHED = ['orders'];

    /**
     * @var array<string, array{prefix: string, pad: int}>
     */
    public const SEQUENCES = [
        'orders' => ['prefix' => 'ORD', 'pad' => 5],
        'purchase_references' => ['prefix' => 'PR', 'pad' => 5],
        'agreement_quote' => ['prefix' => 'QUO', 'pad' => 5],
        'agreement_design' => ['prefix' => 'DSN', 'pad' => 5],
        'agreement_contract' => ['prefix' => 'CON', 'pad' => 5],
        'agreement_change' => ['prefix' => 'CHG', 'pad' => 5],
        'receipt' => ['prefix' => 'RC', 'pad' => 6],
        'refund' => ['prefix' => 'RF', 'pad' => 6],
        'correction' => ['prefix' => 'CR', 'pad' => 6],
        'delivery_note' => ['prefix' => 'DN', 'pad' => 6],
        'order_return' => ['prefix' => 'RT', 'pad' => 6],
        'po' => ['prefix' => 'PO', 'pad' => 6],
        'cancellation' => ['prefix' => 'CN', 'pad' => 6],
        'ticket' => ['prefix' => 'TK', 'pad' => 6],
    ];

    /** Prefixes reserved by sequences outside this list (tax invoices, stock and store records). */
    public const RESERVED = ['INV', 'CRN', 'DBN', 'SR', 'ST', 'SC', 'SI', 'SA', 'CL', 'CK'];

    public static function find(string $sequence): ?NumberFormat
    {
        if (! isset(self::SEQUENCES[$sequence])) {
            return null;
        }

        return ConfigReleases::numberFormats()[$sequence] ?? null;
    }

    /**
     * The counter period of a moment under a year mode: the Gregorian or Hijri (Umm al-Qura) year, or 0.
     */
    public static function period(string $yearMode, CarbonInterface $at): int
    {
        return match ($yearMode) {
            'hijri' => self::hijriYear($at),
            'none' => 0,
            default => (int) $at->format('Y'),
        };
    }

    public static function hijriYear(CarbonInterface $at): int
    {
        $formatter = new IntlDateFormatter('en@calendar=islamic-umalqura', IntlDateFormatter::NONE, IntlDateFormatter::NONE,
            $at->getTimezone()->getName(), IntlDateFormatter::TRADITIONAL, 'y');

        return (int) $formatter->format($at->getTimestamp());
    }

    /**
     * How a number reads: `PREFIX[-BRANCH][-YEAR]-000042`.
     */
    public static function compose(string $prefix, ?string $branch, ?int $year, int $value, int $pad): string
    {
        $parts = [$prefix];
        if ($branch !== null && $branch !== '') {
            $parts[] = $branch;
        }
        if ($year !== null) {
            $parts[] = (string) $year;
        }
        $parts[] = str_pad((string) $value, $pad, '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }
}
