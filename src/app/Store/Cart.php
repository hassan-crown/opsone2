<?php

namespace App\Store;

use Illuminate\Contracts\Session\Session;

/**
 * The visitor's ready-goods cart (WEB-05), kept in the customer session only: variant id → whole quantity. A cart
 * never reserves stock or fixes a price; both are checked again at checkout. A basket link is never merged into it
 * (SAL-04 step 4).
 */
final class Cart
{
    public const KEY = 'store.cart';

    public const MAX_LINES = 20;

    public const MAX_QUANTITY = 99;

    public function __construct(private readonly Session $session) {}

    /**
     * @return array<int, int>
     */
    public function lines(): array
    {
        $raw = $this->session->get(self::KEY, []);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $id => $qty) {
            if ((int) $id > 0 && (int) $qty > 0) {
                $out[(int) $id] = min(self::MAX_QUANTITY, (int) $qty);
            }
        }

        return $out;
    }

    public function count(): int
    {
        return array_sum($this->lines());
    }

    public function add(int $variantId, int $quantity): void
    {
        $lines = $this->lines();
        if (! isset($lines[$variantId]) && count($lines) >= self::MAX_LINES) {
            return;
        }
        $lines[$variantId] = min(self::MAX_QUANTITY, ($lines[$variantId] ?? 0) + max(1, $quantity));
        $this->session->put(self::KEY, $lines);
    }

    public function set(int $variantId, int $quantity): void
    {
        $lines = $this->lines();
        if ($quantity <= 0) {
            unset($lines[$variantId]);
        } elseif (isset($lines[$variantId])) {
            $lines[$variantId] = min(self::MAX_QUANTITY, $quantity);
        }
        $this->session->put(self::KEY, $lines);
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
    }
}
