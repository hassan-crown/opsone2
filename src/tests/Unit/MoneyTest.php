<?php

use App\Catalog\MinorUnits;
use App\Money\Money;
use App\Money\MoneyOverflow;
use App\Money\Rounding;
use App\Money\VatCalculator;

/*
| Money foundation (Q-34 a, Q-35 a): integer halalas, half-up once per line, allocation without losing a halala.
| The invariant blocks run many random cases with a fixed seed (property-based style, reproducible).
*/

describe('rounding policy', function () {
    it('rounds half up, away from zero, in one place', function (string $numerator, string $denominator, int $expected) {
        expect(Rounding::divide($numerator, $denominator))->toBe($expected);
    })->with([
        ['5', '2', 3], ['-5', '2', -3], ['4', '3', 1], ['5', '3', 2], ['7', '2', 4], ['1', '3', 0],
        ['149', '100', 1], ['150', '100', 2], ['-150', '100', -2], ['0', '7', 0],
    ]);

    it('rounds decimals half up', function (string $value, int $expected) {
        expect(Rounding::decimal($value))->toBe($expected);
    })->with([['499.5', 500], ['499.499', 499], ['0.5', 1], ['-0.5', -1], ['12', 12], ['12.000', 12]]);

    it('refuses values outside the stored range instead of wrapping', function () {
        expect(fn () => Money::of(MinorUnits::MAX + 1))->toThrow(MoneyOverflow::class)
            ->and(fn () => Money::of(MinorUnits::MAX)->plus(Money::of(1)))->toThrow(MoneyOverflow::class)
            ->and(fn () => Money::of(MinorUnits::MAX)->times('2'))->toThrow(MoneyOverflow::class)
            ->and(Money::of(MinorUnits::MAX)->minor)->toBe(MinorUnits::MAX);
    });
});

describe('money', function () {
    it('multiplies a unit price by a decimal quantity with half-up', function () {
        expect(Money::of(333)->times('1.5')->minor)->toBe(500)          // 499.5 → 500
            ->and(Money::of(1_400_000)->times('6')->minor)->toBe(8_400_000)
            ->and(Money::of(999)->times('0.333')->minor)->toBe(333)    // 332.667 → 333
            ->and(Money::of(100)->times('0.005')->minor)->toBe(1);     // 0.5 → 1
    });

    it('takes percentages in basis points with half-up', function () {
        expect(Money::of(9_150_000)->percent(1200)->minor)->toBe(1_098_000)
            ->and(Money::of(1)->percent(5000)->minor)->toBe(1)
            ->and(Money::of(333)->percent(1500)->minor)->toBe(50);      // 49.95 → 50
    });

    it('serializes to integer halalas and formats input text without floats', function () {
        expect(json_encode(['v' => Money::fromInput('1250.5')]))->toBe('{"v":125050}')
            ->and(Money::of(-125050)->toInput())->toBe('-1250.50');
    });

    it('allocates without losing or creating a halala (random cases)', function () {
        mt_srand(6013);
        for ($case = 0; $case < 500; $case++) {
            $amount = mt_rand(0, 1_000_000_00);
            $weights = array_map(fn () => mt_rand(0, 5_000_000), range(1, mt_rand(1, 12)));
            $parts = Money::of($amount)->allocate($weights);

            expect(array_sum(array_map(fn (Money $m) => $m->minor, $parts)))->toBe($amount)
                ->and(count($parts))->toBe(count($weights));
            $total = array_sum($weights);
            foreach ($parts as $i => $part) {
                // Each part is its exact share, floored or plus one halala.
                $exact = $total === 0 ? intdiv($amount, count($weights)) : intdiv($amount * $weights[$i], $total);
                expect($part->minor - $exact)->toBeIn([0, 1]);
            }
        }
    });

    it('allocates negative amounts symmetrically and refuses negative weights', function () {
        expect(array_map(fn (Money $m) => $m->minor, Money::of(-100)->allocate([1, 1, 1])))->toBe([-34, -33, -33])
            ->and(fn () => Money::of(100)->allocate([1, -1]))->toThrow(InvalidArgumentException::class);
    });
});

describe('VAT per line', function () {
    it('extracts VAT from inclusive prices with half-up', function () {
        $line = (new VatCalculator(1500, true))->line(Money::of(1_000_00));
        expect([$line->net->minor, $line->vat->minor, $line->gross->minor])->toBe([86_957, 13_043, 1_000_00]); // 130.434… → 130.43
    });

    it('adds VAT to exclusive prices with half-up', function () {
        $line = (new VatCalculator(1500, false))->line(Money::of(333));
        expect([$line->net->minor, $line->vat->minor, $line->gross->minor])->toBe([333, 50, 383]); // 49.95 → 50
    });

    it('keeps net + vat = gross and the rate within a halala on every line (random cases)', function () {
        mt_srand(3534);
        foreach ([true, false] as $inclusive) {
            $vat = new VatCalculator(1500, $inclusive);
            for ($case = 0; $case < 500; $case++) {
                $amount = Money::of(mt_rand(0, 50_000_000));
                $line = $vat->line($amount);
                expect($line->net->minor + $line->vat->minor)->toBe($line->gross->minor)
                    ->and($inclusive ? $line->gross->minor : $line->net->minor)->toBe($amount->minor)
                    ->and(abs($line->vat->minor * 10000 - $line->net->minor * 1500))->toBeLessThanOrEqual(10000 + 1500);
            }
        }
    });

    it('refuses rates outside 0..50%', function () {
        expect(fn () => new VatCalculator(-1, true))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new VatCalculator(5001, true))->toThrow(InvalidArgumentException::class);
    });
});
