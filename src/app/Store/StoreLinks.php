<?php

namespace App\Store;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\Store\ReadyOrderPayment;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Commissions\CommissionRates;
use App\Enums\OrderKind;
use App\Enums\StateMachine;
use App\Models\Commissions\CommissionSettings;
use App\Models\Commissions\OrderSale;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store\StoreCheckout;
use App\Models\Store\StoreLink;
use App\Models\Store\StoreSettings;
use App\Models\User;
use App\Support\GaplessSequence;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Basket links (M-31, SAL-01..05, 17, 18). A sales member (P-92) saves a ready basket and issues a random link
 * (256-bit token, only its hash is looked up) valid for the default hours up to the facility maximum; issuing never
 * reserves stock or creates a sale. The link's owner is the member who issued it — read from the server-side record
 * at purchase, never from the browser (SAL-05). One checkout attempt at a time; the purchase pins the sale to the owner
 * only if the provider's payment time falls inside the validity and the owner is still active and allowed (SAL-03,
 * SAL-18). Cancel or replace needs P-93 and no attempt in progress.
 */
final class StoreLinks
{
    public const MAX_LINES = 20;

    public function __construct(
        private readonly StoreGate $gate,
        private readonly StoreCatalogue $catalogue,
        private readonly GaplessSequence $sequence,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * M-31-T00 + T01 by the member. Returns the link and its token (shown to its owner).
     *
     * @param  array{lines: list<array{variant_id: int|string, quantity: int|string}>, hours?: int|string|null, note?: string|null}  $data
     * @return array{link: StoreLink, token: string}
     */
    public function issue(User $actor, array $data, ?StoreLink $replaces = null): array
    {
        $settings = StoreSettings::current();
        $hours = (int) ($data['hours'] ?? $settings->link_hours_default);
        if ($hours < 1 || $hours > $settings->link_days_max * 24) {
            throw ValidationException::withMessages(['hours' => __('links.validation.hours', ['max' => $settings->link_days_max * 24])]);
        }
        $lines = [];
        foreach (array_slice($data['lines'], 0, self::MAX_LINES) as $i => $line) {
            $variant = $this->catalogue->variant((int) $line['variant_id']);
            $quantity = (int) $line['quantity'];
            if ($variant === null || $quantity < 1 || $quantity > Cart::MAX_QUANTITY) {
                throw ValidationException::withMessages(["lines.{$i}" => __('links.validation.line')]);
            }
            $lines[$variant->id] = ($lines[$variant->id] ?? 0) + $quantity;
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('links.validation.empty')]);
        }

        return LockingTransaction::run(function () use ($actor, $data, $lines, $hours, $replaces) {
            if (! $this->gate->open()) {
                throw new InvalidTransition(StateMachine::M31, 'T01', 'store_closed');
            }
            $token = Str::random(43);
            $link = new StoreLink([
                'number' => $this->sequence->format('store_links', 'CL', 6),
                'token_hash' => StoreLink::hashToken($token),
                'token' => $token,
                'status' => LinkStatus::Draft,
                'owner_id' => $actor->id,
                'lines' => array_map(fn (int $id, int $qty) => ['variant_id' => $id, 'quantity' => $qty], array_keys($lines), array_values($lines)),
                'note' => isset($data['note']) && $data['note'] !== '' ? mb_substr((string) $data['note'], 0, 500) : null,
                'expires_at' => now()->addHours($hours),
                'replaces_id' => $replaces?->id,
            ]);
            Access::authorize($actor, Permission::P92, $link);
            $link->save();
            $this->transitions->record(StateMachine::M31, 'T00', $link, null, LinkStatus::Draft, $actor, TransitionRecorder::SOURCE_WEB);
            $link->status = LinkStatus::Active;
            $link->save();
            $this->transitions->record(StateMachine::M31, 'T01', $link, LinkStatus::Draft, LinkStatus::Active, $actor, TransitionRecorder::SOURCE_WEB, null, ['expires_at' => $link->expires_at->toIso8601String()]);
            Audit::record(AuditAction::StoreLinkIssued, $link, $actor, after: [
                'number' => $link->number, 'lines' => count($link->lines), 'expires_at' => $link->expires_at->toIso8601String(), 'replaces' => $replaces?->number,
            ], label: $link->number);

            return ['link' => $link, 'token' => $token];
        });
    }

    /**
     * M-31-T07 (P-93): cancel, refused while a checkout attempt is in progress. With `$data` a replacement is issued in
     * the same transaction (the old token stops working before the new one exists).
     *
     * @param  array{lines: list<array{variant_id: int|string, quantity: int|string}>, hours?: int|string|null, note?: string|null}|null  $replacement
     * @return array{link: StoreLink, token: string}|null
     */
    public function cancel(User $actor, StoreLink $link, ?string $reason, int $lockVersion, ?array $replacement = null): ?array
    {
        return LockingTransaction::run(function () use ($actor, $link, $reason, $lockVersion, $replacement) {
            $link = StoreLink::query()->lockForUpdate()->findOrFail($link->id);
            Access::authorize($actor, Permission::P93, $link);
            if ($link->lock_version !== $lockVersion) {
                throw new StaleRecord($link->lock_version);
            }
            if (! in_array($link->status, [LinkStatus::Draft, LinkStatus::Active], true)) {
                throw new InvalidTransition(StateMachine::M31, 'T07', 'not_from_'.$link->status->value);
            }
            $from = $link->status;
            $link->forceFill(['status' => LinkStatus::Cancelled, 'cancel_reason' => $reason, 'lock_version' => $link->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M31, 'T07', $link, $from, LinkStatus::Cancelled, $actor, TransitionRecorder::SOURCE_WEB, $reason);
            Audit::record(AuditAction::StoreLinkCancelled, $link, $actor, before: ['status' => $from->value], after: ['status' => LinkStatus::Cancelled->value, 'replaced' => $replacement !== null], reason: $reason, label: $link->number);

            return $replacement === null ? null : $this->issue($actor, $replacement, $link);
        });
    }

    /**
     * The link behind a token, while it can still be opened (active or with an attempt in progress, not lapsed).
     */
    public function open(string $token): ?StoreLink
    {
        if (preg_match('/^[A-Za-z0-9]{43}$/', $token) !== 1) {
            return null;
        }
        $link = StoreLink::query()->where('token_hash', StoreLink::hashToken($token))->first();

        return $link !== null && $link->status === LinkStatus::Active && ! $link->isLapsed() ? $link : null;
    }

    /**
     * M-31-T02, inside the checkout transaction: one attempt, within validity, for the intended customer.
     */
    public function beginAttempt(StoreLink $link, Customer $customer): StoreLink
    {
        $link = StoreLink::query()->lockForUpdate()->findOrFail($link->id);
        if ($link->status !== LinkStatus::Active) {
            throw new InvalidTransition(StateMachine::M31, 'T02', 'not_from_'.$link->status->value);
        }
        if ($link->isLapsed()) {
            throw new InvalidTransition(StateMachine::M31, 'T02', 'expired');
        }
        if ($link->customer_id !== null && $link->customer_id !== $customer->id) {
            throw new InvalidTransition(StateMachine::M31, 'T02', 'other_customer');
        }
        $owner = $link->owner;
        if (! $owner->canSignIn() || ! Access::can($owner, Permission::P92, $link)) {
            throw new InvalidTransition(StateMachine::M31, 'T02', 'owner_inactive');
        }
        $link->forceFill(['status' => LinkStatus::CheckingOut, 'lock_version' => $link->lock_version + 1])->save();
        $this->transitions->record(StateMachine::M31, 'T02', $link, LinkStatus::Active, LinkStatus::CheckingOut, null, ReadyOrderPayment::SOURCE);

        return $link;
    }

    /**
     * After the attempt: used with the confirmed purchase (T03; T06 for a late result), or back to active / expired
     * after a settled failure (T04). Returns whether the sale was pinned to the link's owner.
     */
    public function endAttempt(StoreCheckout $checkout, bool $purchased, ?CarbonInterface $paidAt = null, ?Order $order = null): bool
    {
        $link = StoreLink::query()->lockForUpdate()->find($checkout->link_id);
        if ($link === null) {
            return false;
        }
        $from = $link->status;
        if (! $purchased) {
            if ($from !== LinkStatus::CheckingOut) {
                return false;
            }
            $to = $link->isLapsed() ? LinkStatus::Expired : LinkStatus::Active;
            $link->forceFill(['status' => $to, 'lock_version' => $link->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M31, 'T04', $link, $from, $to, null, ReadyOrderPayment::SOURCE, null, ['checkout' => $checkout->number]);

            return false;
        }

        // SAL-03 / SAL-18: the provider's payment time decides, not when the notice arrived.
        $inTime = $paidAt !== null && $paidAt->lessThanOrEqualTo($link->expires_at);
        $code = $from === LinkStatus::CheckingOut && $inTime ? 'T03' : 'T06';
        $to = $inTime ? LinkStatus::Used : LinkStatus::Expired;
        if (in_array($from, [LinkStatus::CheckingOut, LinkStatus::Active, LinkStatus::Expired], true)) {
            $link->forceFill(['status' => $to, 'used_order_id' => $inTime ? $checkout->order_id : $link->used_order_id, 'lock_version' => $link->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M31, $code, $link, $from, $to, null, ReadyOrderPayment::SOURCE, $inTime ? null : 'paid_after_expiry', [
                'checkout' => $checkout->number, 'paid_at' => $paidAt?->toIso8601String(),
            ]);
        }
        $pinned = $inTime && $order !== null && $this->pinSale($link, $order) !== null;
        Audit::record(AuditAction::StoreLinkUsed, $link, null, after: [
            'order' => $order?->number, 'in_time' => $inTime, 'sale_pinned' => $pinned,
        ], source: ReadyOrderPayment::SOURCE, label: $link->number);

        return $pinned;
    }

    /**
     * The sale of a ready order bought through a valid link: its owner, with the rate that applies to them now. Never
     * a second sale (one active sale per order); an inactive or no longer allowed owner earns nothing new (SAL-05).
     */
    public function pinSale(StoreLink $link, Order $order): ?OrderSale
    {
        if ($order->kind !== OrderKind::Ready || OrderSale::query()->where('order_id', $order->id)->where('status', OrderSale::ACTIVE)->exists()) {
            return null;
        }
        $owner = $link->owner;
        if (! $owner->canSignIn() || ! Access::can($owner, Permission::P92, $link)) {
            return null;
        }
        $rate = CommissionRates::resolve($owner);

        return OrderSale::query()->create([
            'order_id' => $order->id,
            'user_id' => $owner->id,
            'rate_bp' => $rate['rate_bp'],
            'rate_source' => $rate['source'],
            'manual_lines_eligible' => CommissionSettings::current()->manual_lines_eligible,
            'owner_source' => 'link',
            'agreement_id' => null,
            'status' => OrderSale::ACTIVE,
            'active_key' => $order->id,
            'pinned_at' => now(),
        ]);
    }

    /**
     * @return array<int, int>
     */
    public static function linesOf(StoreLink $link): array
    {
        $out = [];
        foreach ($link->lines as $line) {
            $out[(int) $line['variant_id']] = (int) $line['quantity'];
        }

        return $out;
    }
}
