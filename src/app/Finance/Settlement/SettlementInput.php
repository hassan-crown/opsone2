<?php

namespace App\Finance\Settlement;

use App\Money\Money;
use Illuminate\Validation\ValidationException;

/**
 * Shared input rules of the settlement writes (refunds, corrections, disputes, external documents).
 */
final class SettlementInput
{
    public static function money(string $text, string $field): Money
    {
        try {
            $money = Money::fromInput(trim($text));
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('finance.validation.amount')]);
        }
        if ($money->isNegative() || $money->isZero()) {
            throw ValidationException::withMessages([$field => __('finance.validation.amount')]);
        }

        return $money;
    }

    public static function reason(?string $text, string $field = 'reason', int $max = 500): string
    {
        $text = trim((string) $text);
        if ($text === '' || mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => __('finance.validation.reason')]);
        }

        return $text;
    }
}
