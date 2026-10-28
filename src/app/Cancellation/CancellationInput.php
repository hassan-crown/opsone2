<?php

namespace App\Cancellation;

use App\Inventory\Quantity;
use App\Models\Cancellation\OrderCancellation;
use App\Money\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * What is asked to be cancelled: the whole order, or quantities of some lines (`ref` from CancellableLines). A full
 * cancellation may name the value of done but undelivered work the facility keeps (`retained_minor`, as entered, only
 * with P-37), which stays in the agreement instead of being refunded.
 */
final readonly class CancellationInput
{
    /**
     * @param  list<array{ref: string, quantity_milli: int}>  $lines
     */
    public function __construct(
        public string $scope,
        public array $lines = [],
        public ?int $retainedMinor = null,
    ) {}

    /**
     * @param  array{scope?: mixed, lines?: mixed, retained?: mixed}  $data  validated request fields
     */
    public static function fromInput(array $data): self
    {
        $scope = (string) ($data['scope'] ?? '');
        if (! in_array($scope, [OrderCancellation::FULL, OrderCancellation::PARTIAL], true)) {
            throw ValidationException::withMessages(['scope' => __('cancel.validation.scope')]);
        }
        $lines = [];
        if ($scope === OrderCancellation::PARTIAL) {
            $raw = is_array($data['lines'] ?? null) ? $data['lines'] : [];
            foreach (array_values($raw) as $i => $line) {
                $ref = is_array($line) ? (string) ($line['ref'] ?? '') : '';
                $quantity = is_array($line) ? ($line['quantity'] ?? null) : null;
                if (! Quantity::isValid(is_int($quantity) || is_string($quantity) ? $quantity : null)) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => __('cancel.validation.quantity')]);
                }
                /** @var int|string $quantity */
                $lines[] = ['ref' => $ref, 'quantity_milli' => Quantity::parse($quantity)];
            }
            if ($lines === []) {
                throw ValidationException::withMessages(['lines' => __('cancel.validation.no_lines')]);
            }
            if (count(array_unique(array_column($lines, 'ref'))) !== count($lines)) {
                throw ValidationException::withMessages(['lines' => __('cancel.validation.duplicate_line')]);
            }
        }
        $retained = null;
        $text = $data['retained'] ?? null;
        if ($scope === OrderCancellation::FULL && is_string($text) && trim($text) !== '') {
            try {
                $retained = Money::fromInput($text)->minor;
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['retained' => __('cancel.validation.amount')]);
            }
            if ($retained < 0) {
                throw ValidationException::withMessages(['retained' => __('cancel.validation.amount')]);
            }
        }

        return new self($scope, $lines, $retained);
    }

    public static function of(OrderCancellation $cancellation): self
    {
        return new self($cancellation->scope, $cancellation->lines ?? [], $cancellation->retained_minor);
    }

    public function isFull(): bool
    {
        return $this->scope === OrderCancellation::FULL;
    }

    /**
     * @return array{scope: string, lines: list<array{ref: string, quantity_milli: int}>, retained_minor: int|null}
     */
    public function toArray(): array
    {
        return ['scope' => $this->scope, 'lines' => $this->lines, 'retained_minor' => $this->retainedMinor];
    }
}
