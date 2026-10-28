<?php

namespace App\Money;

use App\Catalog\MinorUnits;
use InvalidArgumentException;
use JsonSerializable;

/**
 * An amount of the facility currency as an integer count of minor units (SAR halalas). Immutable; floats never carry
 * an amount, every operation stays inside ±MinorUnits::MAX (MoneyOverflow otherwise) and every fractional result is
 * rounded once by Rounding (half-up). Serializes to its integer minor units.
 */
final readonly class Money implements JsonSerializable
{
    public const CURRENCY = 'SAR';

    /** Basis points in one whole (100%). */
    public const BP = 10_000;

    private function __construct(public int $minor)
    {
        if (abs($minor) > MinorUnits::MAX) {
            throw MoneyOverflow::of((string) $minor);
        }
    }

    public static function of(int $minor): self
    {
        return new self($minor);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * From the decimal text a person types (`1,250.5` is not accepted; `1250.50` is).
     */
    public static function fromInput(string $text): self
    {
        return new self(MinorUnits::fromInput($text));
    }

    /**
     * @param  iterable<self>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = '0';
        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount->minor);
        }

        return new self(Rounding::toInt($total));
    }

    public function plus(self $other): self
    {
        return new self(Rounding::toInt(bcadd((string) $this->minor, (string) $other->minor)));
    }

    public function minus(self $other): self
    {
        return new self(Rounding::toInt(bcsub((string) $this->minor, (string) $other->minor)));
    }

    public function negate(): self
    {
        return new self(-$this->minor);
    }

    /**
     * Unit price × quantity (a decimal string with up to three places), rounded half-up: 333 × 1.5 = 499.5 → 500.
     */
    public function times(string $quantity): self
    {
        if (preg_match('/^\d{1,9}(\.\d{1,3})?$/', $quantity) !== 1 || ! is_numeric($quantity)) {
            throw new InvalidArgumentException('Not a quantity.');
        }

        return new self(Rounding::decimal(bcmul((string) $this->minor, $quantity, 3)));
    }

    /**
     * A share in basis points (1500 = 15%), rounded half-up.
     */
    public function percent(int $basisPoints): self
    {
        return $this->ratio($basisPoints, self::BP);
    }

    /**
     * this × numerator / denominator, rounded half-up once.
     */
    public function ratio(int $numerator, int $denominator): self
    {
        return new self(Rounding::divide(bcmul((string) $this->minor, (string) $numerator), (string) $denominator));
    }

    /**
     * Splits this amount by weights without losing or creating a halala: each part gets its truncated share and the
     * halalas left over go one by one to the largest remainders (earlier parts first on ties). Sum of parts == this.
     * All-zero weights split equally. Weights must not be negative.
     *
     * @param  list<int>  $weights
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('Nothing to allocate to.');
        }
        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('Allocation weights must not be negative.');
            }
        }
        if (array_sum($weights) === 0) {
            $weights = array_fill(0, count($weights), 1);
        }

        $total = (string) abs($this->minor);
        $sumWeights = (string) array_sum($weights);
        $shares = [];
        $remainders = [];
        $allocated = '0';
        foreach ($weights as $i => $weight) {
            $product = bcmul($total, (string) $weight);
            $shares[$i] = bcdiv($product, $sumWeights, 0);
            $remainders[$i] = bcsub($product, bcmul($shares[$i], $sumWeights));
            $allocated = bcadd($allocated, $shares[$i]);
        }

        $left = (int) bcsub($total, $allocated);
        $order = array_keys($weights);
        usort($order, fn (int $x, int $y) => bccomp($remainders[$y], $remainders[$x]) ?: $x <=> $y);
        foreach (array_slice($order, 0, $left) as $i) {
            $shares[$i] = bcadd($shares[$i], '1');
        }

        $sign = $this->minor < 0 ? -1 : 1;

        return array_map(fn (string $share) => new self($sign * (int) $share), array_values($shares));
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function compare(self $other): int
    {
        return $this->minor <=> $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->minor > $other->minor;
    }

    public function max(self $other): self
    {
        return $this->minor >= $other->minor ? $this : $other;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    /**
     * `1250.50`; for forms and logs only, screens format the integer themselves.
     */
    public function toInput(): string
    {
        return ($this->minor < 0 ? '-' : '').MinorUnits::toInput(abs($this->minor));
    }

    public function jsonSerialize(): int
    {
        return $this->minor;
    }
}
