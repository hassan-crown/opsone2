<?php

namespace App\Agreements;

use App\Catalog\MinorUnits;
use App\Models\FinanceSettings;
use App\Models\Order;
use App\Models\OrderLine;
use App\Money\MoneyOverflow;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalizes priced content entered for a quote or an agreement change (AGR-01): lines with decimal
 * quantity and price text, a discount (percent with up to two places → basis points, or an amount → halalas), terms
 * and the estimated execution days. Server validation is the source of truth; screens mirror it.
 */
final class QuoteInput
{
    public const MAX_LINES = 100;

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:'.self::MAX_LINES],
            'lines.*.order_line_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,6}(\.\d{1,3})?$/'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price' => ['nullable', 'string', 'max:20'],
            'lines.*.commissionable' => ['sometimes', 'boolean'],
            'discount' => ['required', 'array'],
            'discount.type' => ['required', 'in:'.implode(',', [QuotePricing::DISCOUNT_NONE, QuotePricing::DISCOUNT_PERCENT, QuotePricing::DISCOUNT_AMOUNT])],
            'discount.value' => ['nullable', 'string', 'max:20'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'execution_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{lines: list<array{order_line_id: int|null, description: string, quantity: string, unit: string|null, unit_price_minor: int|null, commissionable: bool}>, discount: array{type: string, value: int}, terms: string|null, execution_days: int|null}
     */
    public static function normalize(Order $order, array $data): array
    {
        $data = Validator::make($data, self::rules())->validate();
        $orderLines = OrderLine::query()->where('order_id', $order->id)->whereNull('removed_at')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $lines = [];
        $errors = [];
        foreach (array_values($data['lines']) as $i => $line) {
            $quantity = (string) $line['quantity'];
            if (! is_numeric($quantity) || bccomp($quantity, '0', 3) <= 0) {
                $errors["lines.{$i}.quantity"] = __('agreements.validation.quantity_positive');
            }
            $price = $line['unit_price'] ?? null;
            $priceMinor = null;
            if ($price !== null && trim((string) $price) !== '') {
                if (! MinorUnits::isValidInput($price)) {
                    $errors["lines.{$i}.unit_price"] = __('agreements.validation.amount');
                } else {
                    $priceMinor = MinorUnits::fromInput((string) $price);
                }
            }
            $lineId = isset($line['order_line_id']) ? (int) $line['order_line_id'] : null;
            if ($lineId !== null && ! in_array($lineId, $orderLines, true)) {
                $errors["lines.{$i}.order_line_id"] = __('agreements.validation.order_line');
            }
            $lines[] = [
                'order_line_id' => $lineId,
                'description' => trim((string) $line['description']),
                'quantity' => $quantity,
                'unit' => isset($line['unit']) && $line['unit'] !== '' ? (string) $line['unit'] : null,
                'unit_price_minor' => $priceMinor,
                'commissionable' => (bool) ($line['commissionable'] ?? true),
            ];
        }

        $type = (string) $data['discount']['type'];
        $text = trim((string) ($data['discount']['value'] ?? ''));
        $value = 0;
        if ($type !== QuotePricing::DISCOUNT_NONE) {
            if (! MinorUnits::isValidInput($text)) {
                $errors['discount.value'] = __('agreements.validation.amount');
            } else {
                $value = MinorUnits::fromInput($text);
                if ($type === QuotePricing::DISCOUNT_PERCENT && $value > 10_000) {
                    $errors['discount.value'] = __('agreements.validation.percent');
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'lines' => $lines,
            'discount' => ['type' => $type, 'value' => $value],
            'terms' => isset($data['terms']) && trim((string) $data['terms']) !== '' ? trim((string) $data['terms']) : null,
            'execution_days' => isset($data['execution_days']) ? (int) $data['execution_days'] : null,
        ];
    }

    /**
     * Prices normalized input; an amount discount above the line amounts or any total above the stored range is a
     * field error, never a saturated value.
     *
     * @param  array{lines: list<array<string, mixed>>, discount: array{type: string, value: int}, terms: string|null, execution_days: int|null}  $input
     * @return array<string, mixed> the content to store
     */
    public static function priced(array $input, FinanceSettings $settings): array
    {
        try {
            /** @var list<array{order_line_id?: int|null, description: string, quantity: string, unit?: string|null, unit_price_minor: int|null, commissionable?: bool}> $lines */
            $lines = $input['lines'];
            $priced = QuotePricing::price($lines, $input['discount'], $settings);
        } catch (MoneyOverflow) {
            throw ValidationException::withMessages(['lines' => __('agreements.validation.too_large')]);
        }
        $base = $priced['totals']['base_minor'];
        if ($base !== null && $priced['discount']['amount_minor'] !== null && $priced['discount']['amount_minor'] > $base) {
            throw ValidationException::withMessages(['discount.value' => __('agreements.validation.discount_above_base')]);
        }

        return [...$priced, 'terms' => $input['terms'], 'execution_days' => $input['execution_days']];
    }

    /**
     * Starting lines of a new quote: the order's active lines, with the product's reference price as a suggestion.
     *
     * @return list<array{order_line_id: int, description: string, quantity: string, unit: string|null, unit_price_minor: int|null, commissionable: bool}>
     */
    public static function fromOrder(Order $order): array
    {
        $locale = app()->getLocale();

        return array_values(OrderLine::query()->where('order_id', $order->id)->whereNull('removed_at')->orderBy('position')->get()
            ->map(function (OrderLine $line) use ($locale) {
                $item = is_array($line->snapshot['item'] ?? null) ? $line->snapshot['item'] : [];
                $name = $item['name'] ?? null;
                $name = is_array($name) ? (string) ($name[$locale] ?? $name['ar'] ?? '') : (string) ($name ?? '');
                $description = trim($line->description !== null && $line->description !== '' ? ($name !== '' ? $name.' — '.$line->description : $line->description) : $name);
                $reference = $item['reference_price_minor'] ?? null;

                return [
                    'order_line_id' => $line->id,
                    'description' => $description === '' ? '—' : mb_substr($description, 0, 500),
                    'quantity' => self::quantity((string) $line->quantity),
                    'unit' => $line->unit,
                    'unit_price_minor' => is_int($reference) ? $reference : null,
                    'commissionable' => true,
                ];
            })->all());
    }

    private static function quantity(string $quantity): string
    {
        $quantity = str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;

        return $quantity === '' || $quantity === '0' ? '1' : $quantity;
    }
}
